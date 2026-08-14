import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { buildSecurityControlMenu } from './session-controls-menu';

describe('security control menu capability gates', () => {
  it('shows supported Windows controls and preserves confirmed states', () => {
    expect(buildSecurityControlMenu({
      platform: 'Windows',
      permissions: {},
      privacyModeSupported: true,
      privacyModeImpls: [{ key: 'privacy_mode_impl_virtual_display', label: 'Virtual display' }],
      privacyModeOn: true,
      blockInputOn: true,
      lockAfterSessionEnd: false,
    })).toEqual([
      { id: 'restart', label: 'Restart remote device', checked: false },
      { id: 'elevation', label: 'Request elevation', checked: false },
      { id: 'privacy:privacy_mode_impl_virtual_display', label: 'Privacy mode — Virtual display', checked: true },
      { id: 'blockInput', label: 'Block remote keyboard and mouse', checked: true },
      { id: 'lockAfterSessionEnd', label: 'Request lock after disconnect (best effort)', checked: false },
    ]);
  });

  it('fails closed when permissions are withdrawn', () => {
    const menu = buildSecurityControlMenu({
      platform: 'Windows',
      permissions: { Restart: false, Keyboard: false, PrivacyMode: false, BlockInput: false },
      privacyModeSupported: true,
      privacyModeImpls: [],
      privacyModeOn: false,
      blockInputOn: false,
      lockAfterSessionEnd: false,
    });
    expect(menu).toEqual([]);
  });

  it('uses RustDesk’s legacy screen-curtain implementation when capability exists without a list', () => {
    const menu = buildSecurityControlMenu({
      platform: 'Linux',
      permissions: {},
      privacyModeSupported: true,
      privacyModeImpls: [],
      privacyModeOn: false,
      blockInputOn: false,
      lockAfterSessionEnd: false,
    });
    expect(menu.find((item) => item.id.startsWith('privacy:'))).toEqual({
      id: 'privacy:privacy_mode_impl_mag',
      label: 'Privacy mode',
      checked: false,
    });
    expect(menu.some((item) => item.id === 'elevation')).toBe(false);
    expect(menu.some((item) => item.id === 'blockInput')).toBe(false);
  });
});

describe('View only regression guard', () => {
  it('keeps the existing View only label and local input-suppression copy', () => {
    const source = readFileSync(new URL('./app.ts', import.meta.url), 'utf8');
    expect(source).toContain('<span>View only</span>');
    expect(source).toContain("this.viewOnly ? 'View only — input is not sent' : 'Input enabled'");
    expect(source).toContain('title="Block all input to the remote device"');
  });
});
