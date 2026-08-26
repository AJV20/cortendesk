export function clipboardReceiptNotice(writeSucceeded: boolean): string | undefined {
  if (writeSucceeded) return undefined;

  return 'Remote clipboard received (press Ctrl+V on this page to sync)';
}
