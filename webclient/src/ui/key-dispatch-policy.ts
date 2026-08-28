import type { UiCommand } from '../core/contracts';
import { ControlKey } from '../gen/message';

type KeyCommand = Extract<UiCommand, { c: 'key' }>;

export type KeyDispatchContext = {
  sessionStreaming: boolean;
  viewOnly: boolean;
  keyboardAllowed: boolean;
  displayOnline: boolean;
  latchCtrl: boolean;
  latchAlt: boolean;
};

export type KeyDispatchResult =
  | { ok: true; command: KeyCommand }
  | { ok: false; reason: 'notStreaming' | 'viewOnly' | 'keyboardPermission' | 'offlineDisplay' };

export function prepareKeyCommandForDispatch(
  command: KeyCommand,
  context: KeyDispatchContext,
): KeyDispatchResult {
  if (!context.sessionStreaming) return { ok: false, reason: 'notStreaming' };
  if (context.viewOnly) return { ok: false, reason: 'viewOnly' };
  if (!context.keyboardAllowed) return { ok: false, reason: 'keyboardPermission' };

  const lockScreen = command.keyKind === 'control' && command.value === ControlKey.LockScreen;
  if (lockScreen) {
    return { ok: true, command: { ...command, modifiers: [] } };
  }
  if (!context.displayOnline) return { ok: false, reason: 'offlineDisplay' };

  const modifiers = new Set(command.modifiers);
  if (context.latchCtrl) modifiers.add(ControlKey.Control);
  if (context.latchAlt) modifiers.add(ControlKey.Alt);
  return { ok: true, command: { ...command, modifiers: [...modifiers] } };
}
