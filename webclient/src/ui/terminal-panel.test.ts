import { afterEach, describe, expect, it, vi } from 'vitest';
import { appendBoundedTerminalText, stripTerminalControl, TerminalControlStripper, TerminalPanel } from './terminal-panel';

describe('terminal text rendering safety', () => {
  afterEach(() => vi.useRealTimers());
  it('removes ANSI control sequences but preserves text, newlines, and literal markup', () => {
    expect(stripTerminalControl('\u001b[31mred\u001b[0m\n<b>literal</b>')).toBe('red\n<b>literal</b>');
  });

  it('removes DCS, SOS, PM, and APC control strings including their payloads', () => {
    expect(stripTerminalControl(
      'safe\u001bP1;2|device-control\u001b\\'
      + '\u001bXstart-of-string\u001b\\'
      + '\u001b^private-message\u001b\\'
      + '\u001b_application-command\u001b\\done',
    )).toBe('safedone');
  });

  it('strips fragmented control strings and mixed ST terminators across frames', () => {
    const stripper = new TerminalControlStripper();
    const rendered = [
      stripper.push('safe\u001b]title'),
      stripper.push('sensitive'),
      stripper.push('\u0007end\u001b['),
      stripper.push('31mred\u001bPpayload'),
      stripper.push('\u009cok\u0090hidden'),
      stripper.push('\u001b\\done'),
    ].join('');

    expect(rendered).toBe('safeendredokdone');
  });

  it('keeps sanitizer state across terminalData worker events', () => {
    const panel = new TerminalPanel({
      root: {} as HTMLElement,
      workerUrl: 'worker.js',
      getConfig: () => null,
      toast: () => {},
    }) as any;
    panel.output = { textContent: '', scrollTop: 0, scrollHeight: 0 };
    const encode = new TextEncoder();

    panel.onEvent({ t: 'terminalData', terminalId: 1, data: encode.encode('safe\u001b]title') });
    panel.onEvent({ t: 'terminalData', terminalId: 1, data: encode.encode('sensitive\u0007end') });

    expect(panel.output.textContent).toBe('safeend');
  });

  it('strips raw 8-bit C1 control strings across terminalData events', () => {
    const panel = new TerminalPanel({
      root: {} as HTMLElement,
      workerUrl: 'worker.js',
      getConfig: () => null,
      toast: () => {},
    }) as any;
    panel.output = { textContent: '', scrollTop: 0, scrollHeight: 0 };

    panel.onEvent({ t: 'terminalData', terminalId: 1, data: new Uint8Array([0x70, 0x72, 0x65, 0x90]) });
    panel.onEvent({ t: 'terminalData', terminalId: 1, data: new TextEncoder().encode('secret') });
    panel.onEvent({ t: 'terminalData', terminalId: 1, data: new Uint8Array([0x9c, 0x70, 0x6f, 0x73, 0x74]) });

    expect(panel.output.textContent).toBe('prepost');
  });

  it('disconnects and terminates its independent worker after a terminal error', () => {
    vi.useFakeTimers();
    const panel = new TerminalPanel({
      root: {} as HTMLElement,
      workerUrl: 'worker.js',
      getConfig: () => null,
      toast: () => {},
    }) as any;
    const worker = { postMessage: vi.fn(), terminate: vi.fn() };
    panel.worker = worker;
    panel.output = { textContent: '', scrollTop: 0, scrollHeight: 0 };
    panel.status = { textContent: '' };

    panel.onEvent({ t: 'terminalError', message: 'Terminal failed' });

    expect(worker.postMessage).toHaveBeenCalledWith({ c: 'disconnect' });
    expect(panel.worker).toBeUndefined();
    vi.advanceTimersByTime(249);
    expect(worker.terminate).not.toHaveBeenCalled();
    vi.advanceTimersByTime(1);
    expect(worker.terminate).toHaveBeenCalledOnce();
  });

  it('caps terminal history from the front without cutting the newest output', () => {
    expect(appendBoundedTerminalText('12345', '67890', 7)).toBe('4567890');
  });
});
