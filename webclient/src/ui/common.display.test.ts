import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import type { DisplayInfo } from '../core/contracts';
import { applySwitchDisplay } from './common';

describe('applySwitchDisplay', () => {
  it('preserves the existing View only label', () => {
    expect(readFileSync(new URL('./app.ts', import.meta.url), 'utf8')).toContain('<span>View only</span>');
  });

  it('applies authoritative geometry and display-specific mode metadata together', () => {
    const displays: DisplayInfo[] = [{
      index: 0, x: 0, y: 0, width: 1920, height: 1080, name: 'A', scale: 1,
      online: true, cursorEmbedded: false, resolutions: [],
    }];

    applySwitchDisplay(displays, {
      index: 0, x: 20, y: 30, width: 1600, height: 900,
      cursorEmbedded: true,
      originalResolution: { width: 1920, height: 1080 },
      resolutions: [{ width: 1600, height: 900 }],
    });

    expect(displays[0]).toEqual(expect.objectContaining({
      x: 20, y: 30, width: 1600, height: 900, cursorEmbedded: true,
      originalResolution: { width: 1920, height: 1080 },
      resolutions: [{ width: 1600, height: 900 }],
    }));
  });
});
