import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('../core/crypto', () => ({
  sodiumReady: async () => {},
  decodeB64: () => new Uint8Array(32),
  signOpen: (bytes: Uint8Array) => bytes,
  sealSymmetricKey: () => ({ ourBoxPk: new Uint8Array(32), sealed: new Uint8Array(48), key: new Uint8Array(32) }),
  StreamCipher: class {
    seal(bytes: Uint8Array): Uint8Array { return bytes; }
    open(bytes: Uint8Array): Uint8Array { return bytes; }
  },
}));

import { WorkerHost, type SessionLike } from './session.worker';

function harness() {
  const calls = {
    openTerminal: vi.fn(),
    sendTerminalData: vi.fn(),
    resizeTerminal: vi.fn(),
    closeTerminal: vi.fn(),
  };
  const session = {
    currentState: 'streaming', start: vi.fn(), onSignalingBytes: vi.fn(), onRelayBytes: vi.fn(), relayOpened: vi.fn(),
    setSupportedDecoding: vi.fn(), sendMouse: vi.fn(), sendKey: vi.fn(), switchDisplay: vi.fn(), ctrlAltDel: vi.fn(),
    refresh: vi.fn(), setQuality: vi.fn(), sendClipboardText: vi.fn(), sendChat: vi.fn(), sendFileAction: vi.fn(),
    sendFileResponse: vi.fn(), disconnect: vi.fn(), ...calls,
  } as unknown as SessionLike;
  const host = new WorkerHost({ post: vi.fn() });
  (host as unknown as { session: SessionLike }).session = session;
  return { host, calls };
}

describe('terminal worker bridge', () => {
  let h: ReturnType<typeof harness>;
  beforeEach(() => { h = harness(); });

  it('forwards terminal lifecycle and raw input bytes', () => {
    const data = new Uint8Array([3, 65]);
    h.host.handle({ c: 'terminalOpen', terminalId: 9, rows: 32, cols: 120 });
    h.host.handle({ c: 'terminalData', terminalId: 9, data });
    h.host.handle({ c: 'terminalResize', terminalId: 9, rows: 40, cols: 150 });
    h.host.handle({ c: 'terminalClose', terminalId: 9 });

    expect(h.calls.openTerminal).toHaveBeenCalledWith(9, 32, 120);
    expect(h.calls.sendTerminalData).toHaveBeenCalledWith(9, data);
    expect(h.calls.resizeTerminal).toHaveBeenCalledWith(9, 40, 150);
    expect(h.calls.closeTerminal).toHaveBeenCalledWith(9);
  });
});
