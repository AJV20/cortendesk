// Shared UI helpers for the desktop client (app.ts) and the file-transfer
// window (file-app.ts): icons, state labels, saved-credential storage, session
// config plumbing. app.ts re-exports everything here for back-compat.
import type { DisplayInfo, SessionConfig, SessionState } from '../core/contracts';
import type { DisplayRect } from '../input/mouse-keyboard';

export type RdGlobalConfig = {
  peerId?: string;
  serverKeyB64: string;
  wsIdUrl: string;
  wsRelayUrl: string;
  myId: string;
  myName: string;
  workerUrl?: string;
};

export const QUALITY = { best: 4, balanced: 3, speed: 2 } as const; // ImageQuality enum values

export const OVERLAY_VERSION = 'v0.51b.';

export const STATE_LABEL: Record<SessionState, string> = {
  connecting: 'Connecting',
  rendezvous: 'Contacting server',
  relay: 'Opening relay',
  handshake: 'Securing channel',
  login: 'Authenticating',
  streaming: 'Connected',
  error: 'Error',
  closed: 'Disconnected',
  needAccept: 'Waiting for remote user to accept',
};

export function formatDuration(ms: number): string {
  const total = Math.max(0, Math.floor(ms / 1000));
  const h = Math.floor(total / 3600);
  const m = Math.floor((total % 3600) / 60);
  const s = total % 60;
  const mm = String(m).padStart(2, '0');
  const ss = String(s).padStart(2, '0');
  return h > 0 ? `${h}:${mm}:${ss}` : `${mm}:${ss}`;
}

export function formatMbps(mbps: number): string {
  if (!Number.isFinite(mbps) || mbps < 0) return '0.00 Mbps';
  const n = mbps >= 100 ? mbps.toFixed(0) : mbps >= 10 ? mbps.toFixed(1) : mbps.toFixed(2);
  return `${n} Mbps`;
}

export function peerIdFromSearch(search: string): string | null {
  const id = new URLSearchParams(search).get('id')?.trim();
  return id ? id : null;
}

// ?lo=1 marks "the user logged out on purpose" — it suppresses saved-password
// auto-login until the next explicit connect. Set by the disconnect button so
// a reload after logging out stays on the connect screen.
export function loggedOutFromSearch(search: string): boolean {
  return new URLSearchParams(search).get('lo') === '1';
}

export function resolveWorkerUrl(
  cfgUrl: string | undefined,
  attrUrl: string | null | undefined,
  baseUrl: string,
): string {
  if (cfgUrl) return cfgUrl;
  if (attrUrl) return attrUrl;
  return new URL('session.worker.js', baseUrl).href;
}

export function cursorCss(pngDataUrl: string, hotx: number, hoty: number): string {
  const x = Math.max(0, Math.round(hotx));
  const y = Math.max(0, Math.round(hoty));
  return `url("${pngDataUrl}") ${x} ${y}, auto`;
}

// "Save password" persists only h1 = SHA256(pw||salt) (hex), keyed by device —
// never the plaintext. h1 is a per-device login credential (same thing the
// native RustDesk client stores), so it is scoped per peerId.
const SAVED_PW_PREFIX = 'cortendesk.rdpw.';
export function loadSavedHash(peerId: string): string | null {
  try {
    return localStorage.getItem(SAVED_PW_PREFIX + peerId);
  } catch {
    return null;
  }
}
export function saveSavedHash(peerId: string, hashHex: string): void {
  try {
    localStorage.setItem(SAVED_PW_PREFIX + peerId, hashHex);
  } catch {
    /* storage unavailable / full — non-fatal */
  }
}
export function clearSavedHash(peerId: string): void {
  try {
    localStorage.removeItem(SAVED_PW_PREFIX + peerId);
  } catch {
    /* non-fatal */
  }
}

export function buildSessionConfig(
  g: RdGlobalConfig,
  peerId: string,
  password: string,
  savedHashHex?: string,
  connType?: SessionConfig['connType'],
): SessionConfig {
  return {
    peerId,
    serverKeyB64: g.serverKeyB64,
    wsIdUrl: g.wsIdUrl,
    wsRelayUrl: g.wsRelayUrl,
    password,
    myId: g.myId,
    myName: g.myName,
    savedHashHex,
    ...(connType ? { connType } : {}),
  };
}

export function displayToRect(d: DisplayInfo): DisplayRect {
  return { x: d.x, y: d.y, width: d.width, height: d.height };
}

export type IconName =
  | 'fullscreen'
  | 'fullscreenExit'
  | 'monitor'
  | 'keyboard'
  | 'refresh'
  | 'clipboard'
  | 'pulse'
  | 'power'
  | 'folderTransfer'
  | 'folder'
  | 'folderOpen'
  | 'file'
  | 'drive'
  | 'arrowUp'
  | 'home'
  | 'newFolder'
  | 'trash'
  | 'rename'
  | 'sendRight'
  | 'sendLeft'
  | 'fileUpload'
  | 'eye'
  | 'eyeOff'
  | 'close';

export const ICONS: Record<IconName, { ri: string; svg: string }> = {
  fullscreen: {
    ri: 'ri-fullscreen-line',
    svg: '<path d="M8 3H4a1 1 0 0 0-1 1v4M16 3h4a1 1 0 0 1 1 1v4M8 21H4a1 1 0 0 1-1-1v-4M16 21h4a1 1 0 0 0 1-1v-4"/>',
  },
  fullscreenExit: {
    ri: 'ri-fullscreen-exit-line',
    svg: '<path d="M9 3v5a1 1 0 0 1-1 1H3M15 3v5a1 1 0 0 0 1 1h5M9 21v-5a1 1 0 0 0-1-1H3M15 21v-5a1 1 0 0 1 1-1h5"/>',
  },
  monitor: {
    ri: 'ri-computer-line',
    svg: '<rect x="3" y="4" width="18" height="12" rx="1"/><path d="M9 20h6M12 16v4"/>',
  },
  keyboard: {
    ri: 'ri-keyboard-box-line',
    svg: '<rect x="2" y="6" width="20" height="12" rx="1"/><path d="M6 10h.01M10 10h.01M14 10h.01M18 10h.01M7 14h10"/>',
  },
  refresh: {
    ri: 'ri-refresh-line',
    svg: '<path d="M21 12a9 9 0 1 1-2.64-6.36L21 8"/><path d="M21 3v5h-5"/>',
  },
  clipboard: {
    ri: 'ri-clipboard-line',
    svg: '<rect x="5" y="5" width="14" height="16" rx="1"/><rect x="9" y="3" width="6" height="4" rx="1"/>',
  },
  pulse: {
    ri: 'ri-pulse-line',
    svg: '<polyline points="3 12 7 12 10 5 14 19 17 12 21 12"/>',
  },
  power: {
    ri: 'ri-shut-down-line',
    svg: '<path d="M12 3v8M6.2 6.2a8 8 0 1 0 11.6 0"/>',
  },
  folderTransfer: {
    ri: 'ri-folder-transfer-line',
    svg: '<path d="M3 7V5a1 1 0 0 1 1-1h5l2 2h9a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V7Z"/><path d="M9 13h6M13 10.5 15.5 13 13 15.5"/>',
  },
  folder: {
    ri: 'ri-folder-3-line',
    svg: '<path d="M3 7V5a1 1 0 0 1 1-1h5l2 2h9a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V7Z"/>',
  },
  folderOpen: {
    ri: 'ri-folder-open-line',
    svg: '<path d="M3 7V5a1 1 0 0 1 1-1h5l2 2h8a1 1 0 0 1 1 1v2M3 19l2.4-8.5A1 1 0 0 1 6.4 9.8H21a1 1 0 0 1 1 1.2L19.8 19a1 1 0 0 1-1 .8H4a1 1 0 0 1-1-.8Z"/>',
  },
  file: {
    ri: 'ri-file-line',
    svg: '<path d="M6 3h8l4 4v14a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z"/><path d="M14 3v5h5"/>',
  },
  drive: {
    ri: 'ri-hard-drive-2-line',
    svg: '<rect x="3" y="13" width="18" height="7" rx="1"/><path d="M5 13 8 5h8l3 8M17 16.5h.01"/>',
  },
  arrowUp: {
    ri: 'ri-arrow-up-line',
    svg: '<path d="M12 20V4M5 11l7-7 7 7"/>',
  },
  home: {
    ri: 'ri-home-4-line',
    svg: '<path d="M4 11 12 4l8 7M6 10v10h12V10"/>',
  },
  newFolder: {
    ri: 'ri-folder-add-line',
    svg: '<path d="M3 7V5a1 1 0 0 1 1-1h5l2 2h9a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V7Z"/><path d="M12 10.5v5M9.5 13h5"/>',
  },
  trash: {
    ri: 'ri-delete-bin-line',
    svg: '<path d="M4 7h16M9 7V4h6v3M6.5 7l1 13h9l1-13M10 11v5M14 11v5"/>',
  },
  rename: {
    ri: 'ri-edit-line',
    svg: '<path d="m14.5 5.5 4 4L8 20H4v-4L14.5 5.5ZM12.5 7.5l4 4"/>',
  },
  sendRight: {
    ri: 'ri-arrow-right-line',
    svg: '<path d="M4 12h15M13 6l6 6-6 6"/>',
  },
  sendLeft: {
    ri: 'ri-arrow-left-line',
    svg: '<path d="M20 12H5M11 6l-6 6 6 6"/>',
  },
  fileUpload: {
    ri: 'ri-file-upload-line',
    svg: '<path d="M6 3h8l4 4v14a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z"/><path d="M14 3v5h5M12 17v-6M9.5 13.5 12 11l2.5 2.5"/>',
  },
  eye: {
    ri: 'ri-eye-line',
    svg: '<path d="M2 12s3.6-6.5 10-6.5S22 12 22 12s-3.6 6.5-10 6.5S2 12 2 12Z"/><circle cx="12" cy="12" r="2.6"/>',
  },
  eyeOff: {
    ri: 'ri-eye-off-line',
    svg: '<path d="M4 4l16 16M9.9 5.9A10.6 10.6 0 0 1 12 5.5c6.4 0 10 6.5 10 6.5a17.5 17.5 0 0 1-3.2 3.9M6.1 8A16.9 16.9 0 0 0 2 12s3.6 6.5 10 6.5c1 0 2-.15 2.9-.42"/>',
  },
  close: {
    ri: 'ri-close-line',
    svg: '<path d="M6 6l12 12M18 6 6 18"/>',
  },
};

let remixAvailable: boolean | undefined;
function hasRemixIcons(): boolean {
  if (remixAvailable === undefined) {
    try {
      remixAvailable = typeof document !== 'undefined' && !!document.fonts?.check('16px remixicon');
    } catch {
      remixAvailable = false;
    }
  }
  return remixAvailable;
}

export function iconHtml(name: IconName): string {
  const ic = ICONS[name];
  if (hasRemixIcons()) return `<i class="${ic.ri}"></i>`;
  return (
    '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" ' +
    `stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${ic.svg}</svg>`
  );
}

export function escapeHtml(s: string): string {
  return s
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}
