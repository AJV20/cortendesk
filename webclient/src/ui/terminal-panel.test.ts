import { describe, expect, it } from 'vitest';
import { appendBoundedTerminalText, stripTerminalControl } from './terminal-panel';

describe('terminal text rendering safety', () => {
  it('removes ANSI control sequences but preserves text, newlines, and literal markup', () => {
    expect(stripTerminalControl('\u001b[31mred\u001b[0m\n<b>literal</b>')).toBe('red\n<b>literal</b>');
  });

  it('caps terminal history from the front without cutting the newest output', () => {
    expect(appendBoundedTerminalText('12345', '67890', 7)).toBe('4567890');
  });
});
