import { describe, it, expect } from 'vitest';
import {
  QUALITY,
  STATE_LABEL,
  ICONS,
  buildSessionConfig,
  cursorCss,
  displayToRect,
  formatDuration,
  formatMbps,
  iconHtml,
  loggedOutFromSearch,
  peerIdFromSearch,
  resolveWorkerUrl,
  type IconName,
  type RdGlobalConfig,
} from './app';
import type { DisplayInfo, SessionState } from '../core/contracts';

describe('formatDuration', () => {
  it('formats sub-hour as mm:ss', () => {
    expect(formatDuration(0)).toBe('00:00');
    expect(formatDuration(999)).toBe('00:00');
    expect(formatDuration(61_000)).toBe('01:01');
    expect(formatDuration(59 * 60_000 + 59_000)).toBe('59:59');
  });

  it('formats hour+ as h:mm:ss', () => {
    expect(formatDuration(3_600_000)).toBe('1:00:00');
    expect(formatDuration(3_661_000)).toBe('1:01:01');
    expect(formatDuration(10 * 3_600_000 + 5_000)).toBe('10:00:05');
  });

  it('clamps negative to zero', () => {
    expect(formatDuration(-5000)).toBe('00:00');
  });
});

describe('formatMbps', () => {
  it('scales precision with magnitude', () => {
    expect(formatMbps(0)).toBe('0.00 Mbps');
    expect(formatMbps(3.456)).toBe('3.46 Mbps');
    expect(formatMbps(12.34)).toBe('12.3 Mbps');
    expect(formatMbps(123.4)).toBe('123 Mbps');
  });

  it('sanitizes NaN/negative/Infinity', () => {
    expect(formatMbps(Number.NaN)).toBe('0.00 Mbps');
    expect(formatMbps(-1)).toBe('0.00 Mbps');
    expect(formatMbps(Number.POSITIVE_INFINITY)).toBe('0.00 Mbps');
  });
});

describe('peerIdFromSearch', () => {
  it('extracts and trims id', () => {
    expect(peerIdFromSearch('?id=123456789')).toBe('123456789');
    expect(peerIdFromSearch('?foo=1&id=%20abc%20')).toBe('abc');
  });

  it('returns null when absent or empty', () => {
    expect(peerIdFromSearch('')).toBeNull();
    expect(peerIdFromSearch('?foo=1')).toBeNull();
    expect(peerIdFromSearch('?id=')).toBeNull();
    expect(peerIdFromSearch('?id=%20%20')).toBeNull();
  });
});

describe('loggedOutFromSearch', () => {
  it('is true only for lo=1', () => {
    expect(loggedOutFromSearch('?id=123&lo=1')).toBe(true);
    expect(loggedOutFromSearch('?lo=1')).toBe(true);
    expect(loggedOutFromSearch('?id=123')).toBe(false);
    expect(loggedOutFromSearch('?lo=0')).toBe(false);
    expect(loggedOutFromSearch('')).toBe(false);
  });
});

describe('resolveWorkerUrl', () => {
  it('prefers explicit config url', () => {
    expect(resolveWorkerUrl('/x/w.js', '/y/w.js', 'https://h/rdclient/app.js')).toBe('/x/w.js');
  });

  it('falls back to script data attribute', () => {
    expect(resolveWorkerUrl(undefined, '/rdclient/session.worker.js', 'https://h/a/app.js')).toBe(
      '/rdclient/session.worker.js',
    );
  });

  it('defaults to session.worker.js next to app.js', () => {
    expect(resolveWorkerUrl(undefined, null, 'https://h/rdclient/app.js')).toBe(
      'https://h/rdclient/session.worker.js',
    );
    expect(resolveWorkerUrl('', undefined, 'https://h/deep/path/app.js')).toBe(
      'https://h/deep/path/session.worker.js',
    );
  });
});

describe('cursorCss', () => {
  it('builds a css cursor with hotspot and auto fallback', () => {
    expect(cursorCss('data:image/png;base64,AAA=', 3, 5)).toBe(
      'url("data:image/png;base64,AAA=") 3 5, auto',
    );
  });

  it('clamps negative and rounds fractional hotspots', () => {
    expect(cursorCss('data:x', -2, 4.6)).toBe('url("data:x") 0 5, auto');
  });
});

describe('buildSessionConfig', () => {
  it('maps global config + peer id + password to SessionConfig', () => {
    const g: RdGlobalConfig = {
      serverKeyB64: 'k'.repeat(43) + '=',
      wsIdUrl: 'wss://s/ws/id',
      wsRelayUrl: 'wss://s/ws/relay',
      myId: 'web-1',
      myName: 'CortenDesk',
      workerUrl: '/rdclient/session.worker.js',
    };
    expect(buildSessionConfig(g, '987654321', 'pw')).toEqual({
      peerId: '987654321',
      serverKeyB64: g.serverKeyB64,
      wsIdUrl: g.wsIdUrl,
      wsRelayUrl: g.wsRelayUrl,
      password: 'pw',
      myId: 'web-1',
      myName: 'CortenDesk',
    });
  });
});

describe('displayToRect', () => {
  it('projects DisplayInfo to the input mapper rect', () => {
    const d: DisplayInfo = { index: 1, x: -1920, y: 0, width: 1920, height: 1080, name: 'DP-1', scale: 1 };
    expect(displayToRect(d)).toEqual({ x: -1920, y: 0, width: 1920, height: 1080 });
  });
});

describe('QUALITY', () => {
  it('matches RustDesk ImageQuality enum values', () => {
    expect(QUALITY.best).toBe(4);
    expect(QUALITY.balanced).toBe(3);
    expect(QUALITY.speed).toBe(2);
  });
});

describe('STATE_LABEL', () => {
  it('covers every SessionState', () => {
    const states: SessionState[] = [
      'connecting',
      'rendezvous',
      'relay',
      'handshake',
      'login',
      'streaming',
      'error',
      'closed',
      'needAccept',
    ];
    for (const s of states) {
      expect(STATE_LABEL[s]).toBeTruthy();
    }
  });
});

describe('iconHtml', () => {
  it('renders inline SVG fallback outside the browser (no Remix font)', () => {
    for (const name of Object.keys(ICONS) as IconName[]) {
      const html = iconHtml(name);
      expect(html).toContain('<svg');
      expect(html).toContain('stroke="currentColor"');
      expect(html).toContain('aria-hidden="true"');
    }
  });
});
