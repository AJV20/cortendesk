import { ControlKey } from '../gen/message';
import { describe, expect, it } from 'vitest';
import { buildLockScreenKeyCommand, buildSecurityControlMenu } from './session-controls-menu';

const normalSecurityMenuInput = (overrides: Record<string, unknown> = {}) => ({
  platform: 'linux',
  permissions: { Keyboard: true },
  privacyModeSupported: false,
  privacyModeImpls: [],
  privacyModeOn: false,
  blockInputOn: false,
  lockAfterSessionEnd: false,
  ...overrides,
});

describe('buildSecurityControlMenu', () => {
  it('offers Lock remote screen for a normal session with keyboard permission', () => {
    const items = buildSecurityControlMenu(normalSecurityMenuInput());

    expect(items).toContainEqual({
      id: 'lockScreen',
      label: 'Lock remote screen',
      checked: false,
    });
  });

  it('offers Lock remote screen regardless of peer platform', () => {
    const items = buildSecurityControlMenu(normalSecurityMenuInput({ platform: 'android' }));

    expect(items.map((item) => item.id)).toContain('lockScreen');
  });

  it('hides Lock remote screen for a View only session', () => {
    const items = buildSecurityControlMenu(normalSecurityMenuInput({ viewOnly: true }));

    expect(items.map((item) => item.id)).not.toContain('lockScreen');
  });

  it('hides Lock remote screen without keyboard permission', () => {
    const items = buildSecurityControlMenu(normalSecurityMenuInput({ permissions: { Keyboard: false } }));

    expect(items.map((item) => item.id)).not.toContain('lockScreen');
  });
});

describe('buildLockScreenKeyCommand', () => {
  it('uses the normal control-key press command for LockScreen', () => {
    expect(buildLockScreenKeyCommand()).toEqual({
      c: 'key',
      down: false,
      press: true,
      keyKind: 'control',
      value: ControlKey.LockScreen,
      modifiers: [],
    });
  });
});
