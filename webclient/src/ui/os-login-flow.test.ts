import { describe, expect, it, vi } from 'vitest';
import { OsLoginFlow } from './os-login-flow';

describe('OsLoginFlow', () => {
  it('reuses a loaded saved OS password without writing it again', async () => {
    const client = {
      load: vi.fn(async () => ({ enabled: true as const, password: 'saved-fixture' })),
      save: vi.fn(async () => {}),
      remove: vi.fn(async () => {}),
    };
    const flow = new OsLoginFlow(client);

    await expect(flow.hydrate('abc-123')).resolves.toEqual({
      enabled: true,
      hasSavedPassword: true,
    });
    await expect(flow.prepare('abc-123', true, '')).resolves.toBe('saved-fixture');
    expect(client.save).not.toHaveBeenCalled();
    expect(client.remove).not.toHaveBeenCalled();
  });

  it('scrubs the plaintext after handing a saved password to the session', async () => {
    const client = {
      load: vi.fn(async () => ({ enabled: true as const, password: 'saved-fixture' })),
      save: vi.fn(async () => {}),
      remove: vi.fn(async () => {}),
    };
    const flow = new OsLoginFlow(client);
    await flow.hydrate('peer-one');

    await expect(flow.prepare('peer-one', true, '')).resolves.toBe('saved-fixture');
    await expect(flow.prepare('peer-one', true, '')).rejects.toThrow('Enter the remote OS password.');
  });

  it('saves and returns a newly typed OS password for this connection', async () => {
    const client = {
      load: vi.fn(async () => ({ enabled: false as const })),
      save: vi.fn(async () => {}),
      remove: vi.fn(async () => {}),
    };
    const flow = new OsLoginFlow(client);

    await flow.hydrate('abc-123');
    await expect(flow.prepare('abc-123', true, 'new-fixture')).resolves.toBe('new-fixture');
    expect(client.save).toHaveBeenCalledWith('abc-123', 'new-fixture');
    await expect(flow.prepare('abc-123', true, '')).rejects.toThrow('Enter the remote OS password.');
    expect(client.remove).not.toHaveBeenCalled();
  });

  it('deletes a previously saved password when auto-login is disabled', async () => {
    const client = {
      load: vi.fn(async () => ({ enabled: true as const, password: 'saved-fixture' })),
      save: vi.fn(async () => {}),
      remove: vi.fn(async () => {}),
    };
    const flow = new OsLoginFlow(client);

    await flow.hydrate('abc-123');
    await expect(flow.prepare('abc-123', false, '')).resolves.toBeUndefined();
    expect(client.remove).toHaveBeenCalledWith('abc-123');
    expect(client.save).not.toHaveBeenCalled();
  });

  it('ignores a stale setting response after the peer changes', async () => {
    let resolveFirst!: (value: { enabled: true; password: string }) => void;
    let resolveSecond!: (value: { enabled: true; password: string }) => void;
    const first = new Promise<{ enabled: true; password: string }>((resolve) => { resolveFirst = resolve; });
    const second = new Promise<{ enabled: true; password: string }>((resolve) => { resolveSecond = resolve; });
    const client = {
      load: vi.fn((peerId: string) => peerId === 'first' ? first : second),
      save: vi.fn(async () => {}),
      remove: vi.fn(async () => {}),
    };
    const flow = new OsLoginFlow(client);

    const oldHydration = flow.hydrate('first');
    const currentHydration = flow.hydrate('second');
    resolveSecond({ enabled: true, password: 'second-fixture' });
    await expect(currentHydration).resolves.toEqual({ enabled: true, hasSavedPassword: true });
    resolveFirst({ enabled: true, password: 'first-fixture' });
    await expect(oldHydration).resolves.toBeNull();
    await expect(flow.prepare('second', true, '')).resolves.toBe('second-fixture');
  });
});
