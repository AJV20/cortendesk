import { describe, expect, it } from 'vitest';
import { ControlKey } from '../gen/message';
import { buildLockScreenKeyCommand } from './session-controls-menu';
import { prepareKeyCommandForDispatch } from './key-dispatch-policy';

const context = (overrides: Record<string, unknown> = {}) => ({
  sessionStreaming: true,
  viewOnly: false,
  keyboardAllowed: true,
  displayOnline: true,
  latchCtrl: false,
  latchAlt: false,
  ...overrides,
});

describe('prepareKeyCommandForDispatch', () => {
  it('isolates LockScreen from active modifiers and display geometry', () => {
    expect(prepareKeyCommandForDispatch(
      buildLockScreenKeyCommand(),
      context({ displayOnline: false, latchCtrl: true, latchAlt: true }),
    )).toEqual({ ok: true, command: buildLockScreenKeyCommand() });
  });

  it('blocks LockScreen in View only or without Keyboard permission', () => {
    expect(prepareKeyCommandForDispatch(buildLockScreenKeyCommand(), context({ sessionStreaming: false })))
      .toEqual({ ok: false, reason: 'notStreaming' });
    expect(prepareKeyCommandForDispatch(buildLockScreenKeyCommand(), context({ viewOnly: true })))
      .toEqual({ ok: false, reason: 'viewOnly' });
    expect(prepareKeyCommandForDispatch(buildLockScreenKeyCommand(), context({ keyboardAllowed: false })))
      .toEqual({ ok: false, reason: 'keyboardPermission' });
  });

  it('preserves normal key display gating and merges active modifiers', () => {
    const key = {
      c: 'key' as const,
      down: false,
      press: true,
      keyKind: 'control' as const,
      value: ControlKey.Escape,
      modifiers: [],
    };
    expect(prepareKeyCommandForDispatch(key, context({ displayOnline: false })))
      .toEqual({ ok: false, reason: 'offlineDisplay' });
    expect(prepareKeyCommandForDispatch(key, context({ latchCtrl: true, latchAlt: true })))
      .toEqual({
        ok: true,
        command: { ...key, modifiers: [ControlKey.Control, ControlKey.Alt] },
      });
  });
});
