import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

describe('integrated popover layout', () => {
  it('keeps the combined controls menu bounded and scrollable inside the viewport', () => {
    const css = readFileSync(new URL('./app.css', import.meta.url), 'utf8');
    const popovers = [...css.matchAll(/(?:^|\n)\.rd-pop\s*\{([^}]*)\}/gs)].map((match) => match[1] ?? '');
    const popover = popovers.find((body) => body.includes('position: fixed')) ?? '';

    expect(popover).toMatch(/max-height:\s*calc\(100vh\s*-\s*20px\)/);
    expect(popover).toMatch(/box-sizing:\s*border-box/);
    expect(popover).toMatch(/overflow-y:\s*auto/);
    expect(popover).toMatch(/overscroll-behavior:\s*contain/);
  });
});
