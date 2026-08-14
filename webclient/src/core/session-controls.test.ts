import { describe, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';

vi.mock('./crypto', () => ({
  decodeB64: () => new Uint8Array(32),
  signOpen: (bytes: Uint8Array) => bytes,
  sealSymmetricKey: () => ({ ourBoxPk: new Uint8Array(32), sealed: new Uint8Array(48), key: new Uint8Array(32) }),
  StreamCipher: class {
    seal(bytes: Uint8Array): Uint8Array { return bytes; }
    open(bytes: Uint8Array): Uint8Array { return bytes; }
  },
}));

import { OptionMessage_BoolOption } from '../gen/message';
import { Session } from './session';

function makeSession(): { session: Session; sent: unknown[] } {
  const sent: unknown[] = [];
  const session = new Session(
    {
      peerId: '123456789',
      serverKeyB64: 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
      wsIdUrl: 'ws://example.test/id',
      wsRelayUrl: 'ws://example.test/relay',
      password: '',
      myId: 'controller',
      myName: 'Controller',
    },
    {
      sendSignaling: () => {},
      sendRelay: () => {},
      emit: () => {},
      onVideo: () => {},
      onAudioFormat: () => {},
      onAudioFrame: () => {},
      openRelay: () => {},
      closeAll: () => {},
    },
  );
  (session as unknown as { sendMisc(union: unknown): void }).sendMisc = (union) => sent.push(union);
  return { session, sent };
}

describe('Session media controls', () => {
  it('preserves the existing View only wording', () => {
    const appSource = readFileSync(new URL('../ui/app.ts', import.meta.url), 'utf8');
    expect(appSource).toContain('<span>View only</span>');
  });

  it('sends RustDesk disable_audio when remote playback is turned off', () => {
    const { session, sent } = makeSession();

    (session as unknown as { setRemoteAudioEnabled(enabled: boolean): void }).setRemoteAudioEnabled(false);

    const option = (sent[0] as { $case: string; option: { disable_audio: OptionMessage_BoolOption } });
    expect(option.$case).toBe('option');
    expect(option.option.disable_audio).toBe(OptionMessage_BoolOption.Yes);
  });

  it('sends RustDesk disable_clipboard for the whole session', () => {
    const { session, sent } = makeSession();

    (session as unknown as { setClipboardEnabled(enabled: boolean): void }).setClipboardEnabled(false);

    const option = sent[0] as { $case: string; option: { disable_clipboard: OptionMessage_BoolOption } };
    expect(option.$case).toBe('option');
    expect(option.option.disable_clipboard).toBe(OptionMessage_BoolOption.Yes);
  });

  it('reports local recording state only through client_record_status', () => {
    const { session, sent } = makeSession();

    (session as unknown as { setClientRecording(recording: boolean): void }).setClientRecording(true);
    (session as unknown as { setClientRecording(recording: boolean): void }).setClientRecording(false);

    expect(sent).toEqual([
      { $case: 'client_record_status', client_record_status: true },
      { $case: 'client_record_status', client_record_status: false },
    ]);
  });
});
