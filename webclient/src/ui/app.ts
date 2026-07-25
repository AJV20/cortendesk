// CortenDesk web client — main-thread UI shell: toolbar, slide-out stats, connect overlay.
//
// DOM id contract (the Blade view provides these; each is created here if missing, and
// #rd-canvas / #rd-stats / #rd-overlay are normalized INTO #rd-viewport on mount):
//   #rd-root       page wrapper (toolbar + viewport); gets data-state="<SessionState>"
//   #rd-toolbar    slim top bar — contents rendered by this module
//   #rd-viewport   region between toolbar and page bottom; holds canvas, stats, overlay, toast
//   #rd-canvas     <canvas> transferred to the session worker (replaced with a fresh node on reconnect)
//   #rd-stats      right slide-out stats panel — contents rendered here; .rd-open = visible
//   #rd-overlay    connect overlay — rendered children: #rd-peer-id (row #rd-field-id, hidden when
//                  the peer id is server-injected or in ?id=), #rd-password, #rd-connect,
//                  #rd-overlay-status, #rd-overlay-error; .rd-hidden hides the overlay
//   #rd-toast      transient notification (created here)
//
// Server-injected config:
//   window.__RD__ = { peerId?, serverKeyB64, wsIdUrl, wsRelayUrl, myId, myName, workerUrl? }
// Worker script resolution order: __RD__.workerUrl → <script data-rd-worker="/rdclient/session.worker.js">
// → 'session.worker.js' resolved next to the built app.js (import.meta.url).

import './app.css';
import type {
  DisplayInfo,
  SessionConfig,
  SessionEvent,
  SessionState,
  SessionStats,
  UiCommand,
} from '../core/contracts';
import { attachInput, type DisplayRect } from '../input/mouse-keyboard';
import { readLocalClipboardText } from '../input/clipboard-cursor';
import {
  OVERLAY_VERSION,
  QUALITY,
  STATE_LABEL,
  buildSessionConfig,
  clearSavedHash,
  cursorCss,
  displayToRect,
  formatDuration,
  formatMbps,
  iconHtml,
  loadSavedHash,
  loggedOutFromSearch,
  peerIdFromSearch,
  resolveWorkerUrl,
  saveSavedHash,
  type IconName,
  type RdGlobalConfig,
} from './common';
import { FilePanel } from './file-panel';

// Back-compat: everything that used to live here is re-exported for tests and
// external importers.
export * from './common';

type RdWindow = Window & { __RD__?: RdGlobalConfig };

type Els = {
  root: HTMLElement;
  toolbar: HTMLElement;
  viewport: HTMLElement;
  statsPanel: HTMLElement;
  overlay: HTMLElement;
  toast: HTMLElement;
  peerLabel: HTMLElement;
  stateLabel: HTMLElement;
  monitors: HTMLElement;
  quality: HTMLSelectElement;
  btnFullscreen: HTMLButtonElement;
  btnStats: HTMLButtonElement;
  statCodec: HTMLElement;
  statRes: HTMLElement;
  statFps: HTMLElement;
  statBitrate: HTMLElement;
  statDropped: HTMLElement;
  statDuration: HTMLElement;
  statVersion: HTMLElement;
  overlayPeer: HTMLElement;
  overlayTarget: HTMLElement;
  fieldId: HTMLElement;
  peerIdInput: HTMLInputElement;
  passwordInput: HTMLInputElement;
  saveCheckbox: HTMLInputElement;
  connectBtn: HTMLButtonElement;
  overlayStatus: HTMLElement;
  overlayStatusText: HTMLElement;
  overlayError: HTMLElement;
};

function q<T extends Element>(scope: ParentNode, sel: string): T {
  const el = scope.querySelector<T>(sel);
  if (!el) throw new Error(`rdclient: missing element ${sel}`);
  return el;
}

/**
 * Peer permission name (PermissionInfo_Permission) -> the toolbar control it
 * governs. Permissions with no control here are tracked but change nothing.
 */
export const PERMISSION_CONTROLS: Record<string, { id: string; title: string }> = {
  File: { id: 'rd-btn-files', title: 'File transfer' },
  Clipboard: { id: 'rd-btn-clip', title: 'Send clipboard to remote' },
  Keyboard: { id: 'rd-btn-cad', title: 'Send Ctrl+Alt+Del' },
};

export class RdApp {
  private cfg: RdGlobalConfig | undefined;
  private el!: Els;
  private canvas!: HTMLCanvasElement;
  private worker: Worker | undefined;
  private detach: (() => void) | undefined;
  private workerUrl = '';
  private fixedPeerId = '';
  private peerId = '';
  private state: SessionState = 'closed';
  private canvasTransferred = false;
  private displays: DisplayInfo[] = [];
  private current = 0;
  private stats: SessionStats | undefined;
  private streamStartMs = 0;
  private ticker: ReturnType<typeof setInterval> | undefined;
  private toastTimer: ReturnType<typeof setTimeout> | undefined;
  private pendingHashHex: string | undefined; // h1 emitted this session, pending persist
  private connectedWithSavedHash = false; // this attempt used a stored hash
  private sessionHashHex: string | undefined; // h1 of the live session — lets the file panel log in silently
  /** Peer-advertised permissions for THIS session; absent means granted. */
  private permissions: Record<string, boolean> = {};

  private filePanel: FilePanel | undefined;

  mount(): void {
    (window as unknown as { __rdApp?: RdApp }).__rdApp = this; // console/debug handle
    this.cfg = (window as unknown as RdWindow).__RD__;
    this.ensureDom();
    this.renderToolbar();
    this.renderStats();
    this.renderOverlay();

    const attr = document
      .querySelector('script[data-rd-worker]')
      ?.getAttribute('data-rd-worker');
    this.workerUrl = resolveWorkerUrl(this.cfg?.workerUrl, attr, import.meta.url);

    this.fixedPeerId = this.cfg?.peerId?.trim() || peerIdFromSearch(location.search) || '';
    if (this.fixedPeerId) {
      this.el.fieldId.hidden = true;
      this.el.overlayTarget.hidden = false;
      this.el.overlayPeer.textContent = this.fixedPeerId;
      this.el.peerIdInput.value = this.fixedPeerId;
      this.el.peerLabel.textContent = this.fixedPeerId;
      this.hydrateSavedPassword(this.fixedPeerId);
      this.el.passwordInput.focus();
    } else {
      this.el.fieldId.hidden = false;
      this.el.overlayTarget.hidden = true;
      this.el.peerIdInput.focus();
    }
    this.el.peerIdInput.addEventListener('change', () =>
      this.hydrateSavedPassword(this.el.peerIdInput.value.trim()),
    );

    // Saved password + fixed peer -> sign straight in. Skipped when ?lo=1
    // (the user logged out on purpose) so the connect screen stays put.
    if (this.fixedPeerId && !loggedOutFromSearch(location.search) && loadSavedHash(this.fixedPeerId)) {
      this.onConnectClick();
    }

    // Surface it before they type a password and press Connect.
    const blocked = this.secureContextProblem();
    if (blocked) {
      this.setOverlayError(blocked);
    }

    this.ticker = setInterval(() => {
      const start = this.stats?.startedAtMs || this.streamStartMs;
      this.el.statDuration.textContent =
        start && this.state === 'streaming' ? formatDuration(Date.now() - start) : '—';
    }, 1000);

    window.addEventListener('beforeunload', () => this.post({ c: 'disconnect' }));
    document.addEventListener('fullscreenchange', () => {
      this.el.btnFullscreen.innerHTML = iconHtml(
        document.fullscreenElement ? 'fullscreenExit' : 'fullscreen',
      );
    });
  }

  // --- DOM scaffolding -------------------------------------------------------

  private ensureDom(): void {
    let root = document.getElementById('rd-root');
    if (!root) {
      root = document.createElement('div');
      root.id = 'rd-root';
      document.body.appendChild(root);
    }
    let toolbar = document.getElementById('rd-toolbar');
    if (!toolbar) {
      toolbar = document.createElement('div');
      toolbar.id = 'rd-toolbar';
    }
    root.prepend(toolbar);
    let viewport = document.getElementById('rd-viewport');
    if (!viewport) {
      viewport = document.createElement('div');
      viewport.id = 'rd-viewport';
    }
    root.appendChild(viewport);
    let canvas = document.getElementById('rd-canvas') as HTMLCanvasElement | null;
    if (!canvas) {
      canvas = document.createElement('canvas');
      canvas.id = 'rd-canvas';
    }
    viewport.appendChild(canvas);
    let statsPanel = document.getElementById('rd-stats');
    if (!statsPanel) {
      statsPanel = document.createElement('div');
      statsPanel.id = 'rd-stats';
    }
    viewport.appendChild(statsPanel);
    let overlay = document.getElementById('rd-overlay');
    if (!overlay) {
      overlay = document.createElement('div');
      overlay.id = 'rd-overlay';
    }
    viewport.appendChild(overlay);
    let toast = document.getElementById('rd-toast');
    if (!toast) {
      toast = document.createElement('div');
      toast.id = 'rd-toast';
      viewport.appendChild(toast);
    }
    root.dataset.state = 'closed';
    this.canvas = canvas;
    this.el = {
      root,
      toolbar,
      viewport,
      statsPanel,
      overlay,
      toast,
    } as Els; // remaining refs filled by render*()
  }

  private renderToolbar(): void {
    const btn = (id: string, name: IconName, title: string, extra = ''): string =>
      `<button type="button" class="rd-btn${extra}" id="${id}" title="${title}" aria-label="${title}">${iconHtml(name)}</button>`;
    this.el.toolbar.innerHTML = `
      <div class="rd-tb-left">
        <span class="rd-status-dot" aria-hidden="true"></span>
        <span class="rd-peer" id="rd-peer-label"></span>
        <span class="rd-state" id="rd-state-label"></span>
      </div>
      <div class="rd-tb-right">
        <span class="rd-monitors" id="rd-monitors" hidden></span>
        <select class="rd-quality" id="rd-quality" title="Image quality" aria-label="Image quality">
          <option value="${QUALITY.best}">Best</option>
          <option value="${QUALITY.balanced}" selected>Balanced</option>
          <option value="${QUALITY.speed}">Speed</option>
        </select>
        ${btn('rd-btn-files', 'folderTransfer', 'File transfer')}
        ${btn('rd-btn-clip', 'clipboard', 'Send clipboard to remote')}
        ${btn('rd-btn-cad', 'keyboard', 'Send Ctrl+Alt+Del')}
        ${btn('rd-btn-refresh', 'refresh', 'Refresh video')}
        ${btn('rd-btn-fullscreen', 'fullscreen', 'Fullscreen')}
        ${btn('rd-btn-stats', 'pulse', 'Session stats')}
        ${btn('rd-btn-disconnect', 'power', 'Disconnect', ' rd-danger')}
      </div>`;
    const t = this.el.toolbar;
    this.el.peerLabel = q(t, '#rd-peer-label');
    this.el.stateLabel = q(t, '#rd-state-label');
    this.el.monitors = q(t, '#rd-monitors');
    this.el.quality = q(t, '#rd-quality');
    this.el.btnFullscreen = q(t, '#rd-btn-fullscreen');
    this.el.btnStats = q(t, '#rd-btn-stats');

    this.el.quality.addEventListener('change', () => {
      this.post({ c: 'quality', imageQuality: Number(this.el.quality.value) });
    });
    q<HTMLButtonElement>(t, '#rd-btn-files').addEventListener('click', () => {
      this.openFileTransfer();
    });
    q<HTMLButtonElement>(t, '#rd-btn-clip').addEventListener('click', () => {
      void this.sendClipboard();
    });
    q<HTMLButtonElement>(t, '#rd-btn-cad').addEventListener('click', () => {
      this.post({ c: 'ctrlAltDel' });
      this.toast('Ctrl+Alt+Del sent');
    });
    q<HTMLButtonElement>(t, '#rd-btn-refresh').addEventListener('click', () => {
      this.post({ c: 'refresh' });
    });
    this.el.btnFullscreen.addEventListener('click', () => {
      void this.toggleFullscreen();
    });
    this.el.btnStats.addEventListener('click', () => this.toggleStats());
    q<HTMLButtonElement>(t, '#rd-btn-disconnect').addEventListener('click', () => {
      this.setLoggedOutFlag(true); // an explicit logout must not auto-login on reload
      this.post({ c: 'disconnect' });
      this.setState('closed');
    });
  }

  private renderStats(): void {
    const row = (id: string, label: string): string =>
      `<div class="rd-stat-row"><dt>${label}</dt><dd id="${id}">—</dd></div>`;
    this.el.statsPanel.innerHTML = `
      <div class="rd-stats-head">
        <span>Session</span>
        <button type="button" class="rd-btn" id="rd-stats-close" title="Close" aria-label="Close stats">&times;</button>
      </div>
      <dl class="rd-stats-body">
        ${row('rd-stat-codec', 'Codec')}
        ${row('rd-stat-res', 'Resolution')}
        ${row('rd-stat-fps', 'FPS')}
        ${row('rd-stat-bitrate', 'Bitrate')}
        ${row('rd-stat-dropped', 'Frames dropped')}
        ${row('rd-stat-duration', 'Duration')}
        ${row('rd-stat-version', 'Peer version')}
      </dl>`;
    const p = this.el.statsPanel;
    this.el.statCodec = q(p, '#rd-stat-codec');
    this.el.statRes = q(p, '#rd-stat-res');
    this.el.statFps = q(p, '#rd-stat-fps');
    this.el.statBitrate = q(p, '#rd-stat-bitrate');
    this.el.statDropped = q(p, '#rd-stat-dropped');
    this.el.statDuration = q(p, '#rd-stat-duration');
    this.el.statVersion = q(p, '#rd-stat-version');
    q<HTMLButtonElement>(p, '#rd-stats-close').addEventListener('click', () => this.toggleStats(false));
  }

  private renderOverlay(): void {
    const svg = (paths: string, size = 20): string =>
      `<svg viewBox="0 0 24 24" width="${size}" height="${size}" fill="none" stroke="currentColor" ` +
      `stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths}</svg>`;
    const lock = svg('<rect x="4.5" y="10.5" width="15" height="10.5" rx="2.2"/><path d="M8 10.5V7a4 4 0 0 1 8 0v3.5"/>');
    const device = svg('<rect x="3" y="4" width="18" height="12" rx="1.5"/><path d="M8 20h8M12 16v4"/>');
    const arrow = svg('<path d="M5 12h13M12 5.5 18.5 12 12 18.5"/>', 24);
    this.el.overlay.innerHTML = `
      <div class="rd-card">
        <div class="rd-brand">
          <img class="rd-logo" src="/rdclient/logo.png" alt="CortenDesk" width="60" height="60">
          <span class="rd-wordmark">Corten<span>Desk</span></span>
        </div>
        <div class="rd-tagline">Web-Based Client ${OVERLAY_VERSION}</div>
        <div class="rd-divider" aria-hidden="true"></div>
        <p class="rd-help">Enter the client's temporary or permanent password assigned in the RustDesk client.</p>
        <div class="rd-target" id="rd-target" hidden>
          <span class="rd-target-ic">${device}</span>
          <span class="rd-target-cap">Client ID</span>
          <span class="rd-target-id" id="rd-overlay-peer"></span>
        </div>
        <label class="rd-field" id="rd-field-id" hidden>
          <span class="rd-input">
            <span class="rd-input-ic">${device}</span>
            <input id="rd-peer-id" type="text" inputmode="numeric" autocomplete="off" spellcheck="false" placeholder="Device ID">
          </span>
        </label>
        <div class="rd-connect-row">
          <span class="rd-input">
            <span class="rd-input-ic">${lock}</span>
            <input id="rd-password" type="password" autocomplete="new-password" placeholder="Enter password">
          </span>
          <button type="button" class="rd-go" id="rd-connect" aria-label="Connect">${arrow}</button>
        </div>
        <label class="rd-save">
          <input type="checkbox" id="rd-save-pw">
          <span>Save password on this device</span>
        </label>
        <div class="rd-msg" id="rd-msg">
          <div class="rd-overlay-status" id="rd-overlay-status" hidden>
            <span class="rd-spinner" aria-hidden="true"></span><span id="rd-overlay-status-text"></span>
          </div>
          <div class="rd-overlay-error" id="rd-overlay-error" role="alert" hidden></div>
        </div>
      </div>`;
    const o = this.el.overlay;
    this.el.overlayPeer = q(o, '#rd-overlay-peer');
    this.el.overlayTarget = q(o, '#rd-target');
    this.el.fieldId = q(o, '#rd-field-id');
    this.el.peerIdInput = q(o, '#rd-peer-id');
    this.el.passwordInput = q(o, '#rd-password');
    this.el.saveCheckbox = q(o, '#rd-save-pw');
    this.el.connectBtn = q(o, '#rd-connect');
    this.el.overlayStatus = q(o, '#rd-overlay-status');
    this.el.overlayStatusText = q(o, '#rd-overlay-status-text');
    this.el.overlayError = q(o, '#rd-overlay-error');

    this.el.connectBtn.addEventListener('click', () => this.onConnectClick());
    const enter = (e: KeyboardEvent): void => {
      if (e.key === 'Enter') this.onConnectClick();
    };
    this.el.passwordInput.addEventListener('keydown', enter);
    this.el.peerIdInput.addEventListener('keydown', enter);
  }

  // --- session lifecycle -----------------------------------------------------

  // Reflect any stored credential for this device: tick the box and show a saved
  // placeholder instead of an empty field. A real value never leaves storage.
  private hydrateSavedPassword(peerId: string): void {
    const has = !!peerId && loadSavedHash(peerId) !== null;
    this.el.saveCheckbox.checked = has;
    this.el.passwordInput.value = '';
    this.el.passwordInput.placeholder = has ? 'Saved password — click to change' : 'Enter password';
  }

  /**
   * Why the browser will refuse to run this page's session, or null if it won't.
   *
   * Served over plain http:// the page is not a "secure context", and the two
   * things this client is built on are both gated behind that:
   * `crypto.subtle` (the login handshake hashes with SHA-256) and WebCodecs'
   * `VideoDecoder` (the entire video pipeline). Without the check the first
   * failure surfaces as `Cannot read properties of undefined (reading
   * 'digest')`, which sends operators hunting for a bug in their config —
   * reported in #3.
   *
   * There is no workaround worth shipping: a pure-JS hash would only move the
   * failure to the decoder, which cannot be polyfilled. So say so plainly and
   * point at the ways out.
   */
  private secureContextProblem(): string | null {
    if (typeof isSecureContext !== 'undefined' && !isSecureContext) {
      return 'This page is served over plain HTTP, so the browser blocks the '
        + 'cryptography and video decoding the client needs. Serve the console '
        + 'over HTTPS, or open it as http://localhost, which browsers treat as secure.';
    }

    if (typeof crypto === 'undefined' || !crypto.subtle) {
      return 'This browser is not exposing Web Crypto to the page. That normally '
        + 'means the page is not being served over HTTPS.';
    }

    if (typeof VideoDecoder === 'undefined') {
      return 'This browser has no WebCodecs video decoder. Chrome or Edge over '
        + 'HTTPS is required for the remote screen.';
    }

    return null;
  }

  private onConnectClick(): void {
    if (this.worker && this.state !== 'error' && this.state !== 'closed') return;
    const peerId = (this.el.peerIdInput.value || this.fixedPeerId).trim();
    if (!peerId) {
      this.setOverlayError('Enter a device ID');
      return;
    }
    if (!this.cfg) {
      this.setOverlayError('Missing window.__RD__ configuration');
      return;
    }
    const blocked = this.secureContextProblem();
    if (blocked) {
      this.setOverlayError(blocked);
      return;
    }
    this.peerId = peerId;
    this.setOverlayError(null);
    this.setLoggedOutFlag(false); // connecting again clears the logout marker

    const typed = this.el.passwordInput.value;
    const saved = loadSavedHash(peerId);
    // If the user left the field blank and we have a stored hash, reuse it.
    // If the "save" box is off, forget any stored credential for this device.
    if (!this.el.saveCheckbox.checked) clearSavedHash(peerId);
    this.pendingHashHex = undefined;
    this.connectedWithSavedHash = false;

    let config: SessionConfig;
    if (!typed && saved) {
      this.connectedWithSavedHash = true;
      config = buildSessionConfig(this.cfg, peerId, '', saved);
    } else {
      config = buildSessionConfig(this.cfg, peerId, typed);
    }
    this.startSession(config);
  }

  private startSession(config: SessionConfig): void {
    this.teardown();
    // A fresh connection may be a different peer/credential — retire the panel.
    this.filePanel?.destroy();
    this.filePanel = undefined;
    this.sessionHashHex = config.savedHashHex;
    this.stats = undefined;
    this.streamStartMs = 0;
    this.displays = [];
    this.current = 0;
    const canvas = this.freshCanvas();
    const offscreen = canvas.transferControlToOffscreen();
    const worker = new Worker(this.workerUrl, { type: 'module' });
    this.worker = worker;
    worker.onmessage = (e: MessageEvent) => this.onEvent(e.data as SessionEvent);
    worker.onerror = (e: ErrorEvent) => this.setState('error', e.message || 'session worker failed');
    const cmd: UiCommand = { c: 'connect', config, canvas: offscreen };
    worker.postMessage(cmd, [offscreen]);
    this.detach = attachInput(canvas, (c) => this.post(c), () => this.currentRect());
    this.el.peerLabel.textContent = this.peerId;
    this.resetPermissions();
    this.setState('connecting');
  }

  // A canvas can be transferred to OffscreenCanvas only once; reconnects need a fresh node.
  private freshCanvas(): HTMLCanvasElement {
    if (this.canvasTransferred) {
      const fresh = this.canvas.cloneNode(false) as HTMLCanvasElement;
      this.canvas.replaceWith(fresh);
      this.canvas = fresh;
    }
    this.canvasTransferred = true;
    return this.canvas;
  }

  private teardown(): void {
    this.detach?.();
    this.detach = undefined;
    const w = this.worker;
    this.worker = undefined;
    if (w) setTimeout(() => w.terminate(), 250); // let a pending 'disconnect' flush first
  }

  private post(cmd: UiCommand): void {
    this.worker?.postMessage(cmd);
  }

  private currentRect(): DisplayRect {
    const d = this.displays[this.current];
    if (d) return displayToRect(d);
    if (this.stats?.width && this.stats.height) {
      return { x: 0, y: 0, width: this.stats.width, height: this.stats.height };
    }
    return { x: 0, y: 0, width: 1280, height: 720 };
  }

  // --- worker events ---------------------------------------------------------

  private onEvent(ev: SessionEvent): void {
    switch (ev.t) {
      case 'state':
        this.setState(ev.state, ev.detail);
        break;
      case 'peerInfo': {
        this.displays = ev.displays;
        this.current = ev.current;
        const who = ev.username ? `${ev.username}@${ev.hostname}` : ev.hostname;
        this.el.peerLabel.textContent = who ? `${this.peerId} · ${who}` : this.peerId;
        this.el.statVersion.textContent = ev.version || '—';
        this.renderMonitors();
        document.title = `${this.peerId} — CortenDesk`;
        break;
      }
      case 'stats':
        this.onStats(ev.stats);
        break;
      case 'cursor':
        this.canvas.style.cursor = cursorCss(ev.pngDataUrl, ev.hotx, ev.hoty);
        break;
      case 'cursorPos':
        break; // remote pointer position; local pointer is authoritative here
      case 'clipboard':
        void navigator.clipboard
          ?.writeText(ev.text)
          .then(() => this.toast('Remote clipboard received'))
          .catch(() => this.toast('Remote clipboard received (press Ctrl+V on this page to sync)'));
        break;
      case 'permission':
        this.applyPermission(ev.kind, ev.enabled);
        break;
      case 'credentials':
        this.pendingHashHex = ev.hashHex; // persisted only once the session streams
        this.sessionHashHex = ev.hashHex; // in-memory: reused by the file panel
        break;
      case 'uac':
        if (ev.on) {
          this.toast('UAC prompt is open on the remote screen — approve or cancel it there', true);
        } else {
          this.hideToast();
          this.toast('Remote UAC dialog closed');
        }
        break;
      case 'msgbox': {
        // "wait-*" types (e.g. wait-uac) stay up until the situation resolves;
        // everything else is a normal transient notification.
        const text = ev.text || ev.title;
        if (!text) break;
        this.toast(ev.title && ev.text ? `${ev.title}: ${ev.text}` : text, /^wait/i.test(ev.msgtype));
        break;
      }
      case 'loginError':
        this.teardown();
        if (this.connectedWithSavedHash) {
          // The stored credential no longer works (password changed) — drop it.
          clearSavedHash(this.peerId);
          this.el.saveCheckbox.checked = false;
          this.el.passwordInput.placeholder = 'Enter password';
          this.connectedWithSavedHash = false;
        }
        this.showOverlay();
        this.setOverlayBusy(false);
        this.setOverlayError(ev.message || 'Login failed');
        this.el.passwordInput.focus();
        this.el.passwordInput.select();
        break;
    }
  }

  private persistCredentialIfWanted(): void {
    if (!this.el.saveCheckbox.checked) {
      clearSavedHash(this.peerId);
      return;
    }
    // A freshly-typed password emits a new hash; a reused saved hash keeps the stored one.
    if (this.pendingHashHex) saveSavedHash(this.peerId, this.pendingHashHex);
  }

  private setState(state: SessionState, detail?: string): void {
    this.state = state;
    this.el.root.dataset.state = state;
    this.el.stateLabel.textContent = STATE_LABEL[state];
    switch (state) {
      case 'streaming':
        if (!this.streamStartMs) this.streamStartMs = Date.now();
        this.persistCredentialIfWanted();
        this.hideOverlay();
        this.canvas.focus();
        break;
      case 'error':
        this.teardown();
        this.filePanel?.destroy();
        this.filePanel = undefined;
        this.showOverlay();
        this.setOverlayBusy(false);
        this.setOverlayError(detail || 'Connection failed');
        break;
      case 'closed':
        this.teardown();
        this.filePanel?.destroy();
        this.filePanel = undefined;
        this.showOverlay();
        this.setOverlayBusy(false);
        this.setOverlayStatusText('Disconnected');
        break;
      default:
        this.showOverlay();
        this.setOverlayBusy(true);
        this.setOverlayStatusText(STATE_LABEL[state] + (detail ? ` — ${detail}` : '') + '…');
    }
  }

  private onStats(s: SessionStats): void {
    this.stats = s;
    this.el.statCodec.textContent = s.codec || '—';
    this.el.statRes.textContent = s.width && s.height ? `${s.width}×${s.height}` : '—';
    this.el.statFps.textContent = String(Math.round(s.fps));
    this.el.statBitrate.textContent = formatMbps(s.mbps);
    this.el.statDropped.textContent = String(s.framesDropped);
  }

  private renderMonitors(): void {
    const wrap = this.el.monitors;
    wrap.innerHTML = '';
    wrap.hidden = this.displays.length < 2;
    this.displays.forEach((d, i) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'rd-btn rd-mon' + (i === this.current ? ' rd-active' : '');
      b.textContent = String(i + 1);
      b.title = d.name ? `${d.name} (${d.width}×${d.height})` : `Display ${i + 1}`;
      b.addEventListener('click', () => {
        this.post({ c: 'switchDisplay', index: i });
        this.current = i;
        this.renderMonitors();
      });
      wrap.appendChild(b);
    });
  }

  // --- toolbar actions -------------------------------------------------------

  // Keep ?lo=1 in the URL in sync with "the user logged out on purpose".
  private setLoggedOutFlag(on: boolean): void {
    try {
      const url = new URL(location.href);
      if (on) url.searchParams.set('lo', '1');
      else if (url.searchParams.has('lo')) url.searchParams.delete('lo');
      else return;
      history.replaceState(null, '', url);
    } catch {
      /* history unavailable (odd embed) — auto-login still gated per page load */
    }
  }

  // Slide the file-transfer panel over the live desktop. Its FILE_TRANSFER
  // connection reuses this session's h1 credential — no second password prompt.
  /**
   * Apply a peer-advertised permission to the toolbar.
   *
   * The peer is the only thing that ENFORCES these — a server policy (a
   * CortenDesk strategy, or the user's own settings) is applied on the
   * controlled machine, and it will refuse the capability whatever this client
   * does. Gating here is purely so the operator sees a disabled control instead
   * of clicking one that silently fails.
   *
   * Permissions are assumed granted until the peer says otherwise: it reports
   * the restricted ones after login, and anything it never mentions is allowed.
   */
  private applyPermission(kind: string, enabled: boolean): void {
    this.permissions[kind] = enabled;

    const target = PERMISSION_CONTROLS[kind];
    if (!target) {
      return; // nothing in the toolbar maps to it
    }

    const el = this.el.toolbar.querySelector<HTMLButtonElement>(`#${target.id}`);
    if (!el) {
      return;
    }

    el.disabled = !enabled;
    el.title = enabled ? target.title : `${target.title} — not permitted by this device`;
    el.setAttribute('aria-label', el.title);

    // A capability withdrawn mid-session has to close what it opened.
    if (!enabled && kind === 'File') {
      this.filePanel?.destroy();
      this.filePanel = undefined;
    }

    this.toast(`Peer ${enabled ? 'enabled' : 'disabled'} ${target.title.toLowerCase()}`);
  }

  /** Forget peer permissions — they belong to one session, not to the client. */
  private resetPermissions(): void {
    this.permissions = {};
    for (const { id, title } of Object.values(PERMISSION_CONTROLS)) {
      const el = this.el.toolbar.querySelector<HTMLButtonElement>(`#${id}`);
      if (el) {
        el.disabled = false;
        el.title = title;
        el.setAttribute('aria-label', title);
      }
    }
  }

  private openFileTransfer(): void {
    if (this.state !== 'streaming') {
      this.toast('Connect to a device first');
      return;
    }
    if (this.permissions.File === false) {
      this.toast('This device does not permit file transfer');
      return;
    }
    if (!this.filePanel) {
      this.filePanel = new FilePanel({
        viewport: this.el.viewport,
        workerUrl: this.workerUrl,
        toast: (msg) => this.toast(msg),
        getConfig: () => {
          if (!this.cfg || this.state !== 'streaming') return null;
          return buildSessionConfig(this.cfg, this.peerId, '', this.sessionHashHex, 'fileTransfer');
        },
      });
    }
    this.filePanel.toggle();
  }

  private async sendClipboard(): Promise<void> {
    const text = await readLocalClipboardText();
    if (text === null) {
      this.toast('Clipboard unavailable (permission denied?)');
      return;
    }
    this.post({ c: 'clipboardText', text });
    this.toast('Clipboard sent');
  }

  private async toggleFullscreen(): Promise<void> {
    try {
      if (document.fullscreenElement) await document.exitFullscreen();
      else await this.el.root.requestFullscreen();
    } catch {
      this.toast('Fullscreen unavailable');
    }
  }

  private toggleStats(open?: boolean): void {
    const el = this.el.statsPanel;
    const next = open ?? !el.classList.contains('rd-open');
    el.classList.toggle('rd-open', next);
    this.el.btnStats.setAttribute('aria-pressed', String(next));
  }

  // --- overlay / toast -------------------------------------------------------

  private showOverlay(): void {
    this.el.overlay.classList.remove('rd-hidden');
  }

  private hideOverlay(): void {
    this.el.overlay.classList.add('rd-hidden');
    this.setOverlayBusy(false);
  }

  private setOverlayBusy(busy: boolean): void {
    this.el.connectBtn.disabled = busy;
    this.el.peerIdInput.disabled = busy;
    this.el.passwordInput.disabled = busy;
    if (busy) this.setOverlayError(null);
    else if (!this.el.overlayStatusText.textContent) this.el.overlayStatus.hidden = true;
    this.el.overlayStatus.classList.toggle('rd-busy', busy);
  }

  private setOverlayStatusText(text: string): void {
    this.el.overlayStatus.hidden = false;
    this.el.overlayStatusText.textContent = text;
  }

  private setOverlayError(message: string | null): void {
    this.el.overlayError.hidden = !message;
    this.el.overlayError.textContent = message ?? '';
    if (message) this.el.overlayStatus.hidden = true;
  }

  private toast(msg: string, sticky = false): void {
    const t = this.el.toast;
    t.textContent = msg;
    t.classList.add('rd-show');
    clearTimeout(this.toastTimer);
    if (!sticky) this.toastTimer = setTimeout(() => t.classList.remove('rd-show'), 2600);
  }

  private hideToast(): void {
    clearTimeout(this.toastTimer);
    this.el.toast.classList.remove('rd-show');
  }

  dispose(): void {
    this.teardown();
    this.filePanel?.destroy();
    this.filePanel = undefined;
    if (this.ticker) clearInterval(this.ticker);
  }
}

if (typeof document !== 'undefined' && typeof Worker !== 'undefined') {
  const start = (): void => new RdApp().mount();
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
}
