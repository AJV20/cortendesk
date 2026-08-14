import type { SessionConfig, SessionEvent, UiCommand } from '../core/contracts';

const MAX_TERMINAL_TEXT = 1_000_000;
const utf8 = new TextEncoder();

export function stripTerminalControl(text: string): string {
  return text
    // OSC sequences (window title, hyperlinks, etc.), terminated by BEL or ST.
    .replace(/\u001b\][^\u0007]*(?:\u0007|\u001b\\)/g, '')
    // CSI and short escape sequences. The compact viewer intentionally renders
    // text only; remote bytes are never interpreted as DOM/HTML.
    .replace(/\u001b\[[0-?]*[ -/]*[@-~]/g, '')
    .replace(/\u001b[@-_]/g, '')
    .replace(/\r(?!\n)/g, '\n')
    .replace(/[\u0000\u0008\u000b\u000c\u000e-\u001a\u001c-\u001f\u007f]/g, '');
}

export function appendBoundedTerminalText(current: string, incoming: string, max = MAX_TERMINAL_TEXT): string {
  const combined = current + incoming;
  return combined.length <= max ? combined : combined.slice(combined.length - max);
}

type TerminalPanelDeps = {
  root: HTMLElement;
  workerUrl: string;
  getConfig(): SessionConfig | null;
  toast(message: string): void;
};

export class TerminalPanel {
  private worker: Worker | undefined;
  private shell: HTMLElement | undefined;
  private output: HTMLPreElement | undefined;
  private input: HTMLInputElement | undefined;
  private status: HTMLElement | undefined;
  private persistent: HTMLInputElement | undefined;
  private decoder = new TextDecoder();
  private terminalId = 0;
  private opened = false;
  private resizeObserver: ResizeObserver | undefined;
  private lastRows = 24;
  private lastCols = 80;
  private serviceStorageKey = '';

  constructor(private readonly deps: TerminalPanelDeps) {}

  open(): void {
    if (this.shell) {
      this.input?.focus();
      return;
    }
    const shell = document.createElement('section');
    shell.className = 'rd-terminal-modal';
    shell.setAttribute('role', 'dialog');
    shell.setAttribute('aria-modal', 'true');
    shell.setAttribute('aria-label', 'Remote terminal');
    shell.innerHTML = `
      <div class="rd-terminal-card">
        <header class="rd-terminal-head">
          <div><strong>Remote terminal</strong><span data-terminal-status>Not connected</span></div>
          <button type="button" data-terminal-close aria-label="Close remote terminal">×</button>
        </header>
        <div class="rd-terminal-consent">
          <label><input type="checkbox" data-terminal-persistent> Keep the terminal available after disconnect</label>
          <button type="button" data-terminal-connect>Connect terminal</button>
        </div>
        <pre class="rd-terminal-output" tabindex="0" aria-live="polite" aria-label="Remote terminal output"></pre>
        <form class="rd-terminal-input-row">
          <label for="rd-terminal-input">Command input</label>
          <input id="rd-terminal-input" type="text" autocomplete="off" spellcheck="false" disabled>
          <button type="submit" disabled>Send</button>
        </form>
      </div>`;
    this.deps.root.appendChild(shell);
    this.shell = shell;
    this.output = shell.querySelector<HTMLPreElement>('.rd-terminal-output')!;
    this.input = shell.querySelector<HTMLInputElement>('#rd-terminal-input')!;
    this.status = shell.querySelector<HTMLElement>('[data-terminal-status]')!;
    this.persistent = shell.querySelector<HTMLInputElement>('[data-terminal-persistent]')!;

    shell.querySelector<HTMLButtonElement>('[data-terminal-close]')!.addEventListener('click', () => this.destroy());
    shell.querySelector<HTMLButtonElement>('[data-terminal-connect]')!.addEventListener('click', () => this.connect());
    shell.querySelector<HTMLFormElement>('.rd-terminal-input-row')!.addEventListener('submit', (event) => {
      event.preventDefault();
      this.sendLine();
    });
    this.input.addEventListener('keydown', (event) => this.sendSpecialKey(event));

    if (typeof ResizeObserver !== 'undefined') {
      this.resizeObserver = new ResizeObserver(() => this.resize());
      this.resizeObserver.observe(this.output);
    }
  }

  private connect(): void {
    if (this.worker) return;
    const config = this.deps.getConfig();
    if (!config) {
      this.deps.toast('Connect to the device before opening a terminal');
      return;
    }
    this.serviceStorageKey = `rd:terminal-service:${config.peerId}`;
    const persistent = this.persistent?.checked ?? false;
    let serviceId = '';
    if (persistent) {
      try { serviceId = localStorage.getItem(this.serviceStorageKey) ?? ''; } catch { /* non-fatal */ }
    }
    const terminalConfig: SessionConfig = {
      ...config,
      connType: 'terminal',
      terminalPersistent: persistent,
      terminalServiceId: serviceId,
    };
    this.setStatus('Connecting…');
    this.worker = new Worker(this.deps.workerUrl, { type: 'module' });
    this.worker.onmessage = (event: MessageEvent<SessionEvent>) => this.onEvent(event.data);
    this.worker.onerror = () => this.fail('Terminal worker failed');
    this.worker.postMessage({ c: 'connectTerminal', config: terminalConfig } satisfies UiCommand);
  }

  private onEvent(event: SessionEvent): void {
    switch (event.t) {
      case 'state':
        if (event.state === 'streaming') {
          this.setStatus('Opening terminal…');
          this.worker?.postMessage({
            c: 'terminalOpen', terminalId: 0, rows: this.lastRows, cols: this.lastCols,
          } satisfies UiCommand);
        } else if (event.state === 'error' || event.state === 'closed') {
          this.fail(event.detail || (event.state === 'closed' ? 'Terminal disconnected' : 'Terminal connection failed'));
        } else {
          this.setStatus(event.detail || event.state);
        }
        break;
      case 'loginError':
        this.fail(event.message);
        break;
      case 'terminalOpened':
        if (!event.success) {
          this.fail(event.message || 'The remote terminal could not be opened');
          break;
        }
        this.terminalId = event.terminalId;
        this.opened = true;
        this.setInputEnabled(true);
        this.setStatus(event.replayTerminalOutput ? 'Connected · replaying buffered output' : 'Connected');
        if (event.serviceId && this.persistent?.checked) {
          try { localStorage.setItem(this.serviceStorageKey, event.serviceId); } catch { /* non-fatal */ }
        }
        if (event.persistentSessions.length) {
          this.appendText(`\n[${event.persistentSessions.length} additional persistent session${event.persistentSessions.length === 1 ? '' : 's'} available]\n`);
        }
        this.input?.focus();
        break;
      case 'terminalData':
        this.appendText(stripTerminalControl(this.decoder.decode(event.data, { stream: true })));
        break;
      case 'terminalClosed':
        this.opened = false;
        this.setInputEnabled(false);
        this.setStatus(`Terminal exited with code ${event.exitCode}`);
        break;
      case 'terminalError':
        this.fail(event.message);
        break;
    }
  }

  private sendLine(): void {
    const value = this.input?.value ?? '';
    if (!this.opened || !value) return;
    this.sendBytes(utf8.encode(`${value}\r`));
    if (this.input) this.input.value = '';
  }

  private sendSpecialKey(event: KeyboardEvent): void {
    if (!this.opened) return;
    const special: Record<string, string> = {
      ArrowUp: '\u001b[A', ArrowDown: '\u001b[B', ArrowRight: '\u001b[C', ArrowLeft: '\u001b[D',
      Home: '\u001b[H', End: '\u001b[F', Delete: '\u001b[3~', Tab: '\t',
    };
    let bytes: Uint8Array | undefined;
    if (event.ctrlKey && event.key.length === 1) {
      const code = event.key.toUpperCase().charCodeAt(0);
      if (code >= 64 && code <= 95) bytes = new Uint8Array([code - 64]);
    } else if (special[event.key]) {
      bytes = utf8.encode(special[event.key]!);
    }
    if (!bytes) return;
    event.preventDefault();
    this.sendBytes(bytes);
  }

  private sendBytes(data: Uint8Array): void {
    this.worker?.postMessage({ c: 'terminalData', terminalId: this.terminalId, data } satisfies UiCommand, [data.buffer]);
  }

  private resize(): void {
    if (!this.output) return;
    const style = getComputedStyle(this.output);
    const fontSize = Number.parseFloat(style.fontSize) || 14;
    const lineHeight = Number.parseFloat(style.lineHeight) || fontSize * 1.45;
    const charWidth = fontSize * 0.61;
    const rows = Math.max(8, Math.floor(this.output.clientHeight / lineHeight));
    const cols = Math.max(20, Math.floor(this.output.clientWidth / charWidth));
    if (rows === this.lastRows && cols === this.lastCols) return;
    this.lastRows = rows;
    this.lastCols = cols;
    if (this.opened) {
      this.worker?.postMessage({ c: 'terminalResize', terminalId: this.terminalId, rows, cols } satisfies UiCommand);
    }
  }

  private appendText(text: string): void {
    if (!this.output || !text) return;
    this.output.textContent = appendBoundedTerminalText(this.output.textContent ?? '', text);
    this.output.scrollTop = this.output.scrollHeight;
  }

  private setStatus(message: string): void {
    if (this.status) this.status.textContent = message;
  }

  private setInputEnabled(enabled: boolean): void {
    if (!this.input || !this.shell) return;
    this.input.disabled = !enabled;
    const send = this.shell.querySelector<HTMLButtonElement>('.rd-terminal-input-row button');
    if (send) send.disabled = !enabled;
  }

  private fail(message: string): void {
    this.opened = false;
    this.setInputEnabled(false);
    this.setStatus(message);
    this.appendText(`\n[${message}]\n`);
  }

  destroy(): void {
    if (this.opened) {
      this.worker?.postMessage({ c: 'terminalClose', terminalId: this.terminalId } satisfies UiCommand);
    }
    this.worker?.postMessage({ c: 'disconnect' } satisfies UiCommand);
    this.worker?.terminate();
    this.worker = undefined;
    this.resizeObserver?.disconnect();
    this.resizeObserver = undefined;
    this.shell?.remove();
    this.shell = undefined;
    this.output = undefined;
    this.input = undefined;
    this.status = undefined;
    this.persistent = undefined;
    this.opened = false;
  }
}
