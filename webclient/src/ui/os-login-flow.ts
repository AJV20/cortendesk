import type { OsLoginSetting } from './os-login-settings';

export interface OsLoginSettingsPort {
  load(peerId: string): Promise<OsLoginSetting>;
  save(peerId: string, password: string): Promise<void>;
  remove(peerId: string): Promise<void>;
}

export type OsLoginView = { enabled: boolean; hasSavedPassword: boolean };

export class OsLoginFlow {
  private peerId = '';
  private savedPassword = '';
  private hasSavedPassword = false;
  private hydrationEpoch = 0;

  constructor(private readonly client: OsLoginSettingsPort) {}

  async hydrate(peerId: string): Promise<OsLoginView | null> {
    const epoch = ++this.hydrationEpoch;
    const setting = await this.client.load(peerId);
    if (epoch !== this.hydrationEpoch) return null;
    this.peerId = peerId;
    this.savedPassword = setting.enabled ? setting.password : '';
    this.hasSavedPassword = setting.enabled;
    return {
      enabled: setting.enabled,
      hasSavedPassword: setting.enabled,
    };
  }

  async prepare(peerId: string, enabled: boolean, typedPassword: string): Promise<string | undefined> {
    if (!enabled) {
      if (peerId === this.peerId && this.hasSavedPassword) await this.client.remove(peerId);
      if (peerId === this.peerId) {
        this.savedPassword = '';
        this.hasSavedPassword = false;
      }
      return undefined;
    }
    if (typedPassword) {
      await this.client.save(peerId, typedPassword);
      this.peerId = peerId;
      this.savedPassword = '';
      this.hasSavedPassword = true;
      return typedPassword;
    }
    if (peerId === this.peerId && this.savedPassword) {
      const password = this.savedPassword;
      this.savedPassword = '';
      return password;
    }
    throw new Error('Enter the remote OS password.');
  }
}
