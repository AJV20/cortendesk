import type { DisplayInfo, Encryptor, SessionConfig, SessionEvent, SessionState } from './contracts';
import {
  ChatMessage,
  Clipboard,
  ClipboardFormat,
  ControlKey,
  CursorData,
  FileAction,
  FileResponse,
  FileTransferSendConfirmRequest,
  ImageQuality,
  KeyEvent,
  KeyboardMode,
  Message,
  Misc,
  MouseEvent,
  OptionMessage,
  OptionMessage_BoolOption,
  PeerInfo,
  PermissionInfo_Permission,
  SupportedDecoding,
  SupportedDecoding_PreferCodec,
  CaptureDisplays,
  SwitchDisplay,
  VideoFrame,
} from '../gen/message';
import { ConnType } from '../gen/rendezvous';
import { StreamCipher, decodeB64 } from './crypto';
import { buildPublicKeyMessage, verifyPeerSignedId, verifyServerRelayPk } from './handshake';
import { buildLoginRequest, computeLoginH1, loginHashFromH1 } from './auth';
import { buildPunchHoleRequest, parseRendezvous } from './signaling';
import { buildRequestRelay } from './relay';

export const CLIENT_VERSION = '1.4.0';

// Per-frame flow control. With ack ON, the host sends one frame and waits for
// our video_received before capturing the next — this can never flood the
// browser's decode queue (stable), at the cost of ~1 frame per relay RTT.
// Free-run (ack OFF) is smoother but needs decode-queue backpressure to avoid
// wedging the renderer on a high-bitrate stream; until that's in, keep this ON.
export const VIDEO_ACK_REQUIRED = true;

export interface SessionSinks {
  sendSignaling(bytes: Uint8Array): void;
  sendRelay(bytes: Uint8Array): void;
  emit(ev: SessionEvent): void;
  onVideo(frame: VideoFrame): void;
  onAudioFormat(sampleRate: number, channels: number): void;
  onAudioFrame(data: Uint8Array): void;
  openRelay(relayServer: string): void;
  closeAll(): void;
  onCursor?(cursor: CursorData): void;
  onCursorId?(id: bigint): void;
  onClipboard?(clipboard: Clipboard): void; // compressed or non-text payloads the worker must decode
  onFileResponse?(fr: FileResponse): void; // file-transfer connections: dir/block/digest/done/error
  onFileSendConfirm?(c: FileTransferSendConfirmRequest): void; // upload go-ahead from the peer
}

const utf8Enc = new TextEncoder();
const utf8Dec = new TextDecoder();

function bytesToHex(b: Uint8Array): string {
  let s = '';
  for (const x of b) s += x.toString(16).padStart(2, '0');
  return s;
}
function hexToBytes(hex: string): Uint8Array {
  const out = new Uint8Array(hex.length >> 1);
  for (let i = 0; i < out.length; i++) out[i] = parseInt(hex.substr(i * 2, 2), 16);
  return out;
}

function randomSessionId(): bigint {
  const b = new Uint8Array(8);
  globalThis.crypto.getRandomValues(b);
  b[0]! &= 0x7f; // keep below 2^63: safe for both int64 and uint64 writers
  return new DataView(b.buffer).getBigUint64(0);
}

// Core baseline: VP9/VP8 only. Codec probing (h264/h265/av1, i444) is layered
// on by the worker via its own SupportedDecoding once WebCodecs support is known.
function baselineDecoding(): SupportedDecoding {
  return SupportedDecoding.fromPartial({
    ability_vp9: 1,
    ability_vp8: 1,
    prefer: SupportedDecoding_PreferCodec.Auto,
  });
}

function isManualAccept(error: string): boolean {
  const e = error.toLowerCase();
  return e.includes('wait') && e.includes('accept');
}

export class Session {
  /** Display index we asked for and have not had confirmed yet; null when settled. */
  private pendingDisplay: number | null = null;

  private readonly config: SessionConfig;
  private readonly sinks: SessionSinks;
  private readonly serverEdPk: Uint8Array;
  private readonly sessionId: bigint;
  private state: SessionState = 'connecting';
  private cipher: Encryptor | undefined;
  private peerEdPk: Uint8Array | undefined;
  private uuid = '';
  private decoding: SupportedDecoding = baselineDecoding();
  private loginSent = false;

  constructor(config: SessionConfig, sinks: SessionSinks) {
    this.config = config;
    this.sinks = sinks;
    this.serverEdPk = decodeB64(config.serverKeyB64);
    this.sessionId = randomSessionId();
  }

  get currentState(): SessionState {
    return this.state;
  }

  private get connType(): ConnType {
    return this.config.connType === 'fileTransfer' ? ConnType.FILE_TRANSFER : ConnType.DEFAULT_CONN;
  }

  start(): void {
    this.sinks.sendSignaling(
      buildPunchHoleRequest({
        peerId: this.config.peerId,
        licenceKey: this.config.serverKeyB64,
        version: CLIENT_VERSION,
        connType: this.connType,
      }),
    );
    this.setState('rendezvous');
  }

  onSignalingBytes(b: Uint8Array): void {
    if (this.state === 'error' || this.state === 'closed') return;
    let parsed;
    try {
      parsed = parseRendezvous(b);
    } catch {
      this.fail('malformed rendezvous message');
      return;
    }
    switch (parsed.kind) {
      case 'relayResponse': {
        if (!parsed.pk || parsed.pk.length === 0) {
          this.fail('relay response missing server-signed pk');
          return;
        }
        try {
          this.peerEdPk = verifyServerRelayPk(parsed.pk, this.serverEdPk, this.config.peerId);
        } catch (e) {
          this.fail(`server trust link failed: ${(e as Error).message}`);
          return;
        }
        this.uuid = parsed.uuid;
        this.setState('relay');
        this.sinks.openRelay(parsed.relayServer);
        return;
      }
      case 'punchHoleResponse':
        if (parsed.failure) this.fail(`punch hole failed: ${parsed.failure}`);
        return;
      default:
        return;
    }
  }

  relayOpened(): void {
    this.sinks.sendRelay(
      buildRequestRelay({
        licenceKey: this.config.serverKeyB64,
        peerId: this.config.peerId,
        uuid: this.uuid,
        connType: this.connType,
      }),
    );
    this.setState('handshake');
  }

  async onRelayBytes(b: Uint8Array): Promise<void> {
    if (this.state === 'error' || this.state === 'closed') return;
    if (!this.cipher) {
      this.onFirstRelayFrame(b);
      return;
    }
    let pt: Uint8Array;
    try {
      pt = this.cipher.open(b);
    } catch {
      this.fail('decrypt failed');
      return;
    }
    let msg: Message;
    try {
      msg = Message.decode(pt);
    } catch {
      this.fail('malformed message');
      return;
    }
    await this.dispatch(msg);
  }

  // First relay frame is a PLAINTEXT Message{signed_id}; reply PublicKey in
  // plaintext, then install the stream cipher for everything after.
  private onFirstRelayFrame(b: Uint8Array): void {
    let msg: Message;
    try {
      msg = Message.decode(b);
    } catch {
      this.fail('malformed handshake message');
      return;
    }
    if (msg.union?.$case !== 'signed_id') {
      this.fail(`expected signed_id, got ${msg.union?.$case ?? 'empty message'}`);
      return;
    }
    if (!this.peerEdPk) {
      this.fail('signed_id before relay response');
      return;
    }
    let id: string;
    let boxPk: Uint8Array;
    try {
      ({ id, boxPk } = verifyPeerSignedId(msg.union.signed_id.id, this.peerEdPk));
    } catch {
      this.fail('peer trust link failed: bad SignedId signature');
      return;
    }
    if (id.split('\0')[0] !== this.config.peerId) {
      this.fail(`peer id mismatch in SignedId: got "${id}"`);
      return;
    }
    const { bytes, key } = buildPublicKeyMessage(boxPk);
    this.sinks.sendRelay(bytes);
    this.cipher = new StreamCipher(key);
  }

  private async dispatch(msg: Message): Promise<void> {
    const u = msg.union;
    switch (u?.$case) {
      case 'test_delay':
        // Echo verbatim, immediately — the peer measures RTT from this.
        if (!u.test_delay.from_client) {
          this.sealSend(
            Message.encode({ union: { $case: 'test_delay', test_delay: u.test_delay } }).finish(),
          );
        }
        return;
      case 'hash': {
        this.setState('login');
        let passwordHash: Uint8Array;
        if (this.config.savedHashHex) {
          // Reuse a remembered h1 (SHA256(pw||salt)) — no plaintext needed.
          passwordHash = await loginHashFromH1(hexToBytes(this.config.savedHashHex), u.hash.challenge);
        } else if (this.config.password.length > 0) {
          const h1 = await computeLoginH1(this.config.password, u.hash.salt);
          this.sinks.emit({ t: 'credentials', hashHex: bytesToHex(h1) });
          passwordHash = await loginHashFromH1(h1, u.hash.challenge);
        } else {
          passwordHash = new Uint8Array(0); // empty password -> interactive accept
        }
        this.loginSent = true;
        this.sealSend(
          buildLoginRequest({
            peerId: this.config.peerId,
            passwordHash,
            myId: this.config.myId,
            myName: this.config.myName,
            sessionId: this.sessionId,
            version: CLIENT_VERSION,
            supportedDecoding: this.decoding,
            videoAckRequired: VIDEO_ACK_REQUIRED,
            fileTransfer:
              this.config.connType === 'fileTransfer' ? { dir: '', showHidden: false } : undefined,
          }),
        );
        return;
      }
      case 'login_response': {
        const lr = u.login_response.union;
        if (lr?.$case === 'error') {
          this.sinks.emit({ t: 'loginError', message: lr.error });
          if (isManualAccept(lr.error)) this.setState('needAccept', lr.error);
          return;
        }
        if (lr?.$case === 'peer_info') {
          this.emitPeerInfo(lr.peer_info);
          this.setState('streaming');
        }
        return;
      }
      case 'peer_info': // mid-session display-list change
        this.emitPeerInfo(u.peer_info);
        return;
      case 'video_frame':
        this.sinks.onVideo(u.video_frame);
        if (VIDEO_ACK_REQUIRED) {
          this.sendMisc({ $case: 'video_received', video_received: true });
        }
        return;
      case 'audio_frame':
        this.sinks.onAudioFrame(u.audio_frame.data);
        return;
      case 'cursor_data':
        this.sinks.onCursor?.(u.cursor_data);
        return;
      case 'cursor_id':
        this.sinks.onCursorId?.(u.cursor_id);
        return;
      case 'cursor_position':
        this.sinks.emit({ t: 'cursorPos', x: u.cursor_position.x, y: u.cursor_position.y });
        return;
      case 'clipboard':
        this.handleClipboard(u.clipboard);
        return;
      case 'multi_clipboards':
        for (const cb of u.multi_clipboards.clipboards) this.handleClipboard(cb);
        return;
      case 'file_response':
        this.sinks.onFileResponse?.(u.file_response);
        return;
      case 'file_action':
        // The one FileAction that flows peer -> controller: send_confirm for a
        // file we are uploading whose target did not need an overwrite prompt.
        if (u.file_action.union?.$case === 'send_confirm') {
          this.sinks.onFileSendConfirm?.(u.file_action.union.send_confirm);
        }
        return;
      case 'misc':
        this.dispatchMisc(u.misc.union);
        return;
      case 'message_box':
        this.sinks.emit({
          t: 'msgbox',
          msgtype: u.message_box.msgtype,
          title: u.message_box.title,
          text: u.message_box.text,
          link: u.message_box.link,
        });
        return;
      default:
        return; // TODO: file_*, voice_call, switch_sides — out of scope for core
    }
  }

  private dispatchMisc(u: Misc['union']): void {
    switch (u?.$case) {
      case 'chat_message':
        // Chat is a Misc member, not a top-level Message — same channel as
        // switch_display and refresh_video. Empty texts are keepalive noise.
        if (u.chat_message.text) this.sinks.emit({ t: 'chat', text: u.chat_message.text });
        return;
      case 'permission_info':
        this.sinks.emit({
          t: 'permission',
          kind: PermissionInfo_Permission[u.permission_info.permission] ?? String(u.permission_info.permission),
          enabled: u.permission_info.enabled,
        });
        return;
      case 'audio_format':
        this.sinks.onAudioFormat(u.audio_format.sample_rate, u.audio_format.channels);
        return;
      case 'close_reason':
        this.setState('closed', u.close_reason);
        this.sinks.closeAll();
        return;
      case 'uac':
        // Remote UAC prompt opened/closed. The host's capture pipeline restarts
        // around the secure-desktop switch; the worker kicks the video stream.
        this.sinks.emit({ t: 'uac', on: u.uac });
        return;
      case 'switch_display': {
        // The host's confirmation of which display it is now capturing, and
        // where that display sits in the virtual desktop. Dropping this was a
        // real bug: input coordinates are absolute virtual-desktop positions,
        // so the origin here is what decides which monitor a click lands on.
        const s = u.switch_display;
        // Pruning the capture set (see switchDisplay) stops the old display's
        // service broadcasting, but not instantly: its snapshot can already be
        // in flight when our CaptureDisplays arrives. Until the host confirms
        // the display we actually asked for, a message about a different one is
        // that stale broadcast, and following it is what put input back on the
        // monitor we had just left.
        if (this.pendingDisplay !== null && s.display !== this.pendingDisplay) return;
        this.pendingDisplay = null;
        this.sinks.emit({
          t: 'switchDisplay',
          index: s.display,
          x: s.x,
          y: s.y,
          width: s.width,
          height: s.height,
          cursorEmbedded: s.cursor_embedded,
        });
        return;
      }
      default:
        return; // TODO: back_notification, supported_encoding, ...
    }
  }

  private handleClipboard(cb: Clipboard): void {
    if (!cb.compress && cb.format === ClipboardFormat.Text) {
      this.sinks.emit({ t: 'clipboard', text: utf8Dec.decode(cb.content) });
      return;
    }
    this.sinks.onClipboard?.(cb); // zstd / non-text: worker's job
  }

  private emitPeerInfo(pi: PeerInfo): void {
    const displays: DisplayInfo[] = pi.displays.map((d, index) => ({
      index,
      x: d.x,
      y: d.y,
      width: d.width,
      height: d.height,
      name: d.name,
      scale: d.scale || 1,
    }));
    this.sinks.emit({
      t: 'peerInfo',
      displays,
      username: pi.username,
      hostname: pi.hostname,
      platform: pi.platform,
      version: pi.version,
      current: pi.current_display,
    });
  }

  // Replaces the VP9/VP8 baseline with the worker's real WebCodecs probe.
  // Before the Hash arrives the override rides inside the LoginRequest; after
  // login it is re-advertised via Misc{option.supported_decoding} (e.g. when
  // the video pipeline disables a codec that fails to decode).
  setSupportedDecoding(sd: SupportedDecoding): void {
    this.decoding = sd;
    if (this.loginSent) {
      this.sendMisc({
        $case: 'option',
        option: OptionMessage.fromPartial({ supported_decoding: sd }),
      });
    }
  }

  // ---- outbound controls (post-handshake; silently dropped before cipher) ----

  sendMouse(mask: number, x: number, y: number, modifiers: number[]): void {
    this.sendMessage({
      $case: 'mouse_event',
      mouse_event: MouseEvent.fromPartial({ mask, x, y, modifiers: modifiers as ControlKey[] }),
    });
  }

  sendKey(
    down: boolean,
    press: boolean,
    keyKind: 'chr' | 'control' | 'unicode',
    value: number,
    modifiers: number[],
  ): void {
    const union: KeyEvent['union'] =
      keyKind === 'chr'
        ? { $case: 'chr', chr: value }
        : keyKind === 'unicode'
          ? { $case: 'unicode', unicode: value }
          : { $case: 'control_key', control_key: value as ControlKey };
    this.sendMessage({
      $case: 'key_event',
      key_event: KeyEvent.fromPartial({
        down,
        press,
        union,
        modifiers: modifiers as ControlKey[],
        mode: KeyboardMode.Legacy,
      }),
    });
  }

  switchDisplay(index: number): void {
    // While this is outstanding, a switch_display naming a DIFFERENT display is
    // a stale broadcast rather than news — see dispatchMisc.
    this.pendingDisplay = index;
    this.sendMisc({ $case: 'switch_display', switch_display: SwitchDisplay.fromPartial({ display: index }) });
    // Prune the capture set, or the host keeps us subscribed to the old
    // display's video service. connection.rs switch_display_to only
    // unsubscribes the old service for clients BELOW 1.2.4:
    //
    //   // For versions greater than 1.2.4, a `CaptureDisplays` message will
    //   // be sent immediately. Unnecessary capturers will be removed then.
    //
    // We advertise 1.4.0, so the host waits for this message and we never sent
    // one. The old service kept broadcasting at us and replayed its stored
    // snapshot — a switch_display for the display we had just left, which put
    // our display index back and sent every subsequent click to the wrong
    // monitor. It was also still encoding a display nobody was watching.
    this.sendMisc({
      $case: 'capture_displays',
      capture_displays: CaptureDisplays.fromPartial({ set: [index] }),
    });
  }

  ctrlAltDel(): void {
    this.sendMessage({
      $case: 'key_event',
      key_event: KeyEvent.fromPartial({
        down: false,
        press: true,
        union: { $case: 'control_key', control_key: ControlKey.CtrlAltDel },
        mode: KeyboardMode.Legacy,
      }),
    });
  }

  refresh(): void {
    this.sendMisc({ $case: 'refresh_video', refresh_video: true });
  }

  setQuality(imageQuality: number): void {
    this.sendMisc({
      $case: 'option',
      option: OptionMessage.fromPartial({ image_quality: imageQuality as ImageQuality }),
    });
  }

  setRemoteAudioEnabled(enabled: boolean): void {
    this.sendMisc({
      $case: 'option',
      option: OptionMessage.fromPartial({
        disable_audio: enabled ? OptionMessage_BoolOption.No : OptionMessage_BoolOption.Yes,
      }),
    });
  }

  setClipboardEnabled(enabled: boolean): void {
    this.sendMisc({
      $case: 'option',
      option: OptionMessage.fromPartial({
        disable_clipboard: enabled ? OptionMessage_BoolOption.No : OptionMessage_BoolOption.Yes,
      }),
    });
  }

  setClientRecording(recording: boolean): void {
    this.sendMisc({ $case: 'client_record_status', client_record_status: recording });
  }

  sendClipboardText(text: string): void {
    this.sendMessage({
      $case: 'clipboard',
      clipboard: Clipboard.fromPartial({
        compress: false,
        content: utf8Enc.encode(text),
        format: ClipboardFormat.Text,
      }),
    });
  }

  sendChat(text: string): void {
    // ChatMessage.text is plain UTF-8; protobuf handles the encoding. There is
    // no per-message id or ack in the protocol, so the UI echoes what it sent
    // rather than waiting for confirmation.
    this.sendMisc({ $case: 'chat_message', chat_message: ChatMessage.fromPartial({ text }) });
  }

  // File-transfer connections: outbound FileAction (requests and upload control),
  // and FileResponse for the payload we send when uploading (blocks/done/error
  // always travel as file_response regardless of direction).
  sendFileAction(union: NonNullable<FileAction['union']>): void {
    this.sendMessage({ $case: 'file_action', file_action: { union } });
  }

  sendFileResponse(union: NonNullable<FileResponse['union']>): void {
    this.sendMessage({ $case: 'file_response', file_response: { union } });
  }

  disconnect(): void {
    if (this.state !== 'closed') this.setState('closed');
    this.sinks.closeAll();
  }

  // ---- internals ----

  private sendMessage(union: NonNullable<Message['union']>): void {
    if (!this.cipher) return;
    this.sealSend(Message.encode({ union }).finish());
  }

  private sendMisc(union: NonNullable<Misc['union']>): void {
    this.sendMessage({ $case: 'misc', misc: { union } });
  }

  private sealSend(bytes: Uint8Array): void {
    if (!this.cipher) return;
    this.sinks.sendRelay(this.cipher.seal(bytes));
  }

  private setState(state: SessionState, detail?: string): void {
    this.state = state;
    this.sinks.emit(detail === undefined ? { t: 'state', state } : { t: 'state', state, detail });
  }

  private fail(detail: string): void {
    this.setState('error', detail);
    this.sinks.closeAll();
  }
}
