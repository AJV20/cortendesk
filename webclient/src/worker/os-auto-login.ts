export type OsAutoLoginResult = 'sent' | 'blocked' | 'cancelled' | 'already-attempted';

export interface OsAutoLoginSinks {
  eligible(): boolean;
  sendMouse(mask: number, x: number, y: number): void;
  sendPassword(password: string): boolean;
}

export interface OsAutoLoginDeps {
  sleep(ms: number): Promise<void>;
}

const defaultDeps: OsAutoLoginDeps = {
  sleep: (ms) => new Promise((resolve) => setTimeout(resolve, ms)),
};

export class OsAutoLoginAttempt {
  private started = false;
  private epoch = 0;

  constructor(
    private readonly sinks: OsAutoLoginSinks,
    private readonly deps: OsAutoLoginDeps = defaultDeps,
  ) {}

  cancel(): void {
    this.epoch += 1;
  }

  async start(password: string): Promise<OsAutoLoginResult> {
    if (this.started) return 'already-attempted';
    this.started = true;
    const epoch = ++this.epoch;
    if (!this.sinks.eligible()) return 'blocked';

    this.sinks.sendMouse(10, 0, 0);
    await this.deps.sleep(50);
    if (epoch !== this.epoch) return 'cancelled';
    this.sinks.sendMouse(0, 0, 0);
    await this.deps.sleep(50);
    if (epoch !== this.epoch) return 'cancelled';
    this.sinks.sendMouse(0, 3, 3);
    await this.deps.sleep(50);
    if (epoch !== this.epoch) return 'cancelled';
    this.sinks.sendMouse(9, 0, 0);
    this.sinks.sendMouse(10, 0, 0);
    await this.deps.sleep(1200);
    if (epoch !== this.epoch) return 'cancelled';

    return this.sinks.sendPassword(password) ? 'sent' : 'blocked';
  }
}
