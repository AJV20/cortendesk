export type OsLoginSetting =
  | { enabled: false }
  | { enabled: true; password: string };

type Fetcher = (input: RequestInfo | URL, init?: RequestInit) => Promise<Response>;

const SETTINGS_ERROR = 'OS auto-login settings are unavailable.';

export function canUseOsLoginSettings(
  url: string | undefined,
  csrfToken: string | undefined,
  secureContext: boolean,
): boolean {
  return secureContext && Boolean(url?.trim()) && Boolean(csrfToken?.trim());
}

export class OsLoginSettingsClient {
  constructor(
    private readonly url: string,
    private readonly csrfToken: string,
    private readonly fetcher: Fetcher = fetch,
  ) {}

  async remove(peerId: string): Promise<void> {
    if (!/^[A-Za-z0-9_-]{1,255}$/.test(peerId)) throw new Error(SETTINGS_ERROR);
    const response = await this.fetcher(this.url, {
      method: 'DELETE',
      credentials: 'same-origin',
      cache: 'no-store',
      redirect: 'error',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': this.csrfToken,
      },
      body: JSON.stringify({ peerId }),
    });
    if (response.status !== 204 || response.redirected) throw new Error(SETTINGS_ERROR);
  }

  async save(peerId: string, password: string): Promise<void> {
    if (!/^[A-Za-z0-9_-]{1,255}$/.test(peerId) || password.length < 1 || password.length > 1024) {
      throw new Error(SETTINGS_ERROR);
    }
    const response = await this.fetcher(this.url, {
      method: 'PUT',
      credentials: 'same-origin',
      cache: 'no-store',
      redirect: 'error',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': this.csrfToken,
      },
      body: JSON.stringify({ peerId, password }),
    });
    if (response.status !== 204 || response.redirected) throw new Error(SETTINGS_ERROR);
  }

  async load(peerId: string): Promise<OsLoginSetting> {
    const response = await this.fetcher(
      `${this.url}${this.url.includes('?') ? '&' : '?'}peerId=${encodeURIComponent(peerId)}`,
      {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        redirect: 'error',
        headers: { Accept: 'application/json' },
      },
    );
    const contentType = response.headers.get('content-type')?.toLowerCase() ?? '';
    if (!response.ok || response.redirected || !contentType.startsWith('application/json')) {
      throw new Error(SETTINGS_ERROR);
    }

    let body: unknown;
    try {
      body = await response.json();
    } catch {
      throw new Error(SETTINGS_ERROR);
    }
    if (typeof body !== 'object' || body === null) {
      throw new Error(SETTINGS_ERROR);
    }
    const candidate = body as { enabled?: unknown; password?: unknown };
    const keys = Object.keys(candidate).sort();
    if (
      candidate.enabled === false
      && keys.length === 1
      && keys[0] === 'enabled'
    ) {
      return { enabled: false };
    }
    if (
      candidate.enabled !== true
      || typeof candidate.password !== 'string'
      || candidate.password.length < 1
      || candidate.password.length > 1024
      || keys.length !== 2
      || keys[0] !== 'enabled'
      || keys[1] !== 'password'
    ) {
      throw new Error(SETTINGS_ERROR);
    }

    return { enabled: true, password: candidate.password };
  }
}
