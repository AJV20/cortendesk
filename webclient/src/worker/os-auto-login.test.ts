import { afterEach, describe, expect, it, vi } from 'vitest';
import { OsAutoLoginAttempt } from './os-auto-login';

afterEach(() => {
  vi.useRealTimers();
});

describe('OsAutoLoginAttempt', () => {
  it('mirrors RustDesk activation timing before sending the password once', async () => {
    vi.useFakeTimers();
    const mouse: Array<[number, number, number]> = [];
    const passwords: string[] = [];
    const attempt = new OsAutoLoginAttempt({
      eligible: () => true,
      sendMouse: (mask, x, y) => mouse.push([mask, x, y]),
      sendPassword: (password) => {
        passwords.push(password);
        return true;
      },
    });

    const result = attempt.start('remote-os-secret');
    expect(mouse).toEqual([[10, 0, 0]]);
    expect(passwords).toEqual([]);

    await vi.advanceTimersByTimeAsync(50);
    expect(mouse).toEqual([[10, 0, 0], [0, 0, 0]]);
    await vi.advanceTimersByTimeAsync(50);
    expect(mouse).toEqual([[10, 0, 0], [0, 0, 0], [0, 3, 3]]);
    await vi.advanceTimersByTimeAsync(50);
    expect(mouse).toEqual([
      [10, 0, 0],
      [0, 0, 0],
      [0, 3, 3],
      [9, 0, 0],
      [10, 0, 0],
    ]);
    expect(passwords).toEqual([]);

    await vi.advanceTimersByTimeAsync(1199);
    expect(passwords).toEqual([]);
    await vi.advanceTimersByTimeAsync(1);
    await expect(result).resolves.toBe('sent');
    expect(passwords).toEqual(['remote-os-secret']);
    await expect(attempt.start('second-secret')).resolves.toBe('already-attempted');
    expect(passwords).toEqual(['remote-os-secret']);
  });

  it('cancels an in-flight attempt before any later input or password frame', async () => {
    vi.useFakeTimers();
    const mouse: Array<[number, number, number]> = [];
    const passwords: string[] = [];
    const attempt = new OsAutoLoginAttempt({
      eligible: () => true,
      sendMouse: (mask, x, y) => mouse.push([mask, x, y]),
      sendPassword: (password) => {
        passwords.push(password);
        return true;
      },
    });

    const result = attempt.start('must-not-send');
    expect(mouse).toEqual([[10, 0, 0]]);
    attempt.cancel();
    await vi.runAllTimersAsync();

    await expect(result).resolves.toBe('cancelled');
    expect(mouse).toEqual([[10, 0, 0]]);
    expect(passwords).toEqual([]);
  });
});
