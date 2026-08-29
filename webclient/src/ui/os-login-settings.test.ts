import { describe, expect, it, vi } from 'vitest';
import { canUseOsLoginSettings, OsLoginSettingsClient } from './os-login-settings';

describe('OsLoginSettingsClient', () => {
  it('is available only with endpoint config in a secure browser context', () => {
    expect(canUseOsLoginSettings('/webclient/os-login', 'csrf', true)).toBe(true);
    expect(canUseOsLoginSettings('/webclient/os-login', 'csrf', false)).toBe(false);
    expect(canUseOsLoginSettings('', 'csrf', true)).toBe(false);
    expect(canUseOsLoginSettings('/webclient/os-login', '', true)).toBe(false);
  });

  it('loads a strictly shaped no-store OS-login setting', async () => {
    const fetcher = vi.fn(async () => new Response(
      JSON.stringify({ enabled: true, password: 'remote-os-secret' }),
      { status: 200, headers: { 'content-type': 'application/json' } },
    ));
    const client = new OsLoginSettingsClient('/webclient/os-login', 'csrf-token', fetcher);

    await expect(client.load('abc-123')).resolves.toEqual({
      enabled: true,
      password: 'remote-os-secret',
    });
    expect(fetcher).toHaveBeenCalledWith(
      '/webclient/os-login?peerId=abc-123',
      expect.objectContaining({
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        redirect: 'error',
      }),
    );
  });

  it('accepts a disabled setting only when no password is present', async () => {
    const fetcher = vi.fn(async () => new Response(
      JSON.stringify({ enabled: false }),
      { status: 200, headers: { 'content-type': 'application/json' } },
    ));
    const client = new OsLoginSettingsClient('/webclient/os-login', 'csrf-token', fetcher);

    await expect(client.load('abc-123')).resolves.toEqual({ enabled: false });
  });

  it.each([
    { enabled: true, password: '' },
    { enabled: true, password: 'x'.repeat(1025) },
    { enabled: false, password: 'unexpected' },
    { enabled: true, password: 'fixture', extra: true },
  ])('rejects malformed or expanded response shapes', async (body) => {
    const fetcher = vi.fn(async () => new Response(
      JSON.stringify(body),
      { status: 200, headers: { 'content-type': 'application/json' } },
    ));
    const client = new OsLoginSettingsClient('/webclient/os-login', 'csrf-token', fetcher);

    await expect(client.load('abc-123')).rejects.toThrow('OS auto-login settings are unavailable.');
  });

  it('rejects credential data with a non-JSON media type', async () => {
    const fetcher = vi.fn(async () => new Response(
      JSON.stringify({ enabled: true, password: 'fixture' }),
      { status: 200, headers: { 'content-type': 'text/plain' } },
    ));
    const client = new OsLoginSettingsClient('/webclient/os-login', 'csrf-token', fetcher);

    await expect(client.load('abc-123')).rejects.toThrow('OS auto-login settings are unavailable.');
  });

  it('stores a password with CSRF protection and requires an empty success response', async () => {
    const fetcher = vi.fn(async () => new Response(null, { status: 204 }));
    const client = new OsLoginSettingsClient('/webclient/os-login', 'csrf-token', fetcher);

    await expect(client.save('abc-123', 'remote-os-secret')).resolves.toBeUndefined();
    expect(fetcher).toHaveBeenCalledWith('/webclient/os-login', expect.objectContaining({
      method: 'PUT',
      credentials: 'same-origin',
      cache: 'no-store',
      redirect: 'error',
      body: JSON.stringify({ peerId: 'abc-123', password: 'remote-os-secret' }),
      headers: expect.objectContaining({
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': 'csrf-token',
      }),
    }));
  });

  it('removes a saved OS password with an idempotent CSRF-protected delete', async () => {
    const fetcher = vi.fn(async () => new Response(null, { status: 204 }));
    const client = new OsLoginSettingsClient('/webclient/os-login', 'csrf-token', fetcher);

    await expect(client.remove('abc-123')).resolves.toBeUndefined();
    expect(fetcher).toHaveBeenCalledWith('/webclient/os-login', expect.objectContaining({
      method: 'DELETE',
      credentials: 'same-origin',
      cache: 'no-store',
      redirect: 'error',
      body: JSON.stringify({ peerId: 'abc-123' }),
      headers: expect.objectContaining({ 'X-CSRF-TOKEN': 'csrf-token' }),
    }));
  });
});
