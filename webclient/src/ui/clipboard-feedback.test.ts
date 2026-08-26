import { describe, expect, it } from 'vitest';
import { clipboardReceiptNotice } from './clipboard-feedback';

describe('clipboardReceiptNotice', () => {
  it('stays quiet when the remote clipboard is copied successfully', () => {
    expect(clipboardReceiptNotice(true)).toBeUndefined();
  });

  it('explains the manual fallback when browser clipboard access fails', () => {
    expect(clipboardReceiptNotice(false)).toBe(
      'Remote clipboard received (press Ctrl+V on this page to sync)',
    );
  });
});
