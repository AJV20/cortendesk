import { describe, it, expect } from 'vitest';
import { ControlKey } from '../gen/message';
import {
  MouseType,
  MouseButton,
  buttonMask,
  mapCoords,
  domButtonToMask,
  wheelDelta,
  legacyKeyCommand,
} from './mouse-keyboard';

function kev(props: { key: string; location?: number }): KeyboardEvent {
  return { key: props.key, location: props.location ?? 0 } as unknown as KeyboardEvent;
}

describe('buttonMask', () => {
  it('packs buttons<<3 | type', () => {
    expect(buttonMask(MouseType.DOWN, MouseButton.LEFT)).toBe(0x09);
    expect(buttonMask(MouseType.UP, MouseButton.RIGHT)).toBe(0x12);
    expect(buttonMask(MouseType.DOWN, MouseButton.MIDDLE)).toBe(0x21);
    expect(buttonMask(MouseType.MOVE, 0)).toBe(0);
    expect(buttonMask(MouseType.WHEEL, 0)).toBe(3);
  });
});

describe('mapCoords', () => {
  const rect = { left: 10, top: 20, width: 800, height: 450 } as DOMRect;

  it('scales canvas-local pixels to display resolution with display origin offset', () => {
    const display = { x: 1920, y: 0, width: 1920, height: 1080 };
    expect(mapCoords(410, 245, rect, display)).toEqual({ x: 2880, y: 540 });
  });

  it('maps rect corners to display corners', () => {
    const display = { x: -1920, y: -100, width: 1920, height: 1080 };
    expect(mapCoords(10, 20, rect, display)).toEqual({ x: -1920, y: -100 });
    expect(mapCoords(810, 470, rect, display)).toEqual({ x: 0, y: 980 });
  });

  it('rounds to integers', () => {
    const display = { x: 0, y: 0, width: 1920, height: 1080 };
    const { x, y } = mapCoords(11, 21, rect, display); // 1*2.4=2.4, 1*2.4=2.4
    expect(x).toBe(2);
    expect(y).toBe(2);
    expect(Number.isInteger(x)).toBe(true);
    expect(Number.isInteger(y)).toBe(true);
  });

  it('accounts for object-fit letterboxing (pillarbox: element wider than display)', () => {
    // 2000x1000 element (aspect 2.0) showing a 1920x1080 desktop (aspect 1.778):
    // side bars of (2000 - 1000*1.778)/2 = 111.11px. A click at the video's left
    // edge maps to x=0, and the video's right edge to x=1920 — NOT compressed.
    const el = { left: 0, top: 0, width: 2000, height: 1000 } as DOMRect;
    const display = { x: 0, y: 0, width: 1920, height: 1080 };
    expect(mapCoords(1000, 500, el, display)).toEqual({ x: 960, y: 540 }); // center stays center
    expect(mapCoords(112, 500, el, display).x).toBe(1); // ~left edge of image
    expect(mapCoords(1888, 500, el, display).x).toBe(1919); // ~right edge of image
    // clicks in the bars clamp to the display bounds
    expect(mapCoords(10, 500, el, display).x).toBe(0);
    expect(mapCoords(1995, 500, el, display).x).toBe(1920);
  });

  it('accounts for object-fit letterboxing (letterbox: element taller than display)', () => {
    const el = { left: 0, top: 0, width: 1600, height: 1200 } as DOMRect; // aspect 1.333
    const display = { x: 0, y: 0, width: 1920, height: 1080 }; // aspect 1.778
    // bars top/bottom: (1200 - 1600/1.778)/2 = 150px
    expect(mapCoords(800, 600, el, display)).toEqual({ x: 960, y: 540 });
    expect(mapCoords(800, 151, el, display).y).toBe(1); // ~top edge of image
    expect(mapCoords(800, 10, el, display).y).toBe(0); // in the top bar -> clamped
  });
});

describe('domButtonToMask', () => {
  it('maps DOM button numbers to RustDesk button bits (middle is 0x04)', () => {
    expect(domButtonToMask(0)).toBe(MouseButton.LEFT);
    expect(domButtonToMask(1)).toBe(MouseButton.MIDDLE);
    expect(domButtonToMask(2)).toBe(MouseButton.RIGHT);
    expect(domButtonToMask(3)).toBe(MouseButton.BACK);
    expect(domButtonToMask(4)).toBe(MouseButton.FORWARD);
    expect(domButtonToMask(5)).toBe(0);
  });
});

describe('wheelDelta', () => {
  it('inverts DOM deltaY sign into unit steps', () => {
    expect(wheelDelta(100)).toBe(-1);
    expect(wheelDelta(1)).toBe(-1);
    expect(wheelDelta(-3)).toBe(1);
    expect(wheelDelta(-120)).toBe(1);
    expect(wheelDelta(0)).toBe(0);
  });
});

describe('legacyKeyCommand', () => {
  it('printable ASCII -> chr codepoint', () => {
    expect(legacyKeyCommand(kev({ key: 'a' }), true)).toEqual({ keyKind: 'chr', value: 97 });
    expect(legacyKeyCommand(kev({ key: 'W' }), true)).toEqual({ keyKind: 'chr', value: 87 });
    expect(legacyKeyCommand(kev({ key: '/' }), false)).toEqual({ keyKind: 'chr', value: 47 });
  });

  it('non-ASCII printable -> unicode codepoint', () => {
    expect(legacyKeyCommand(kev({ key: 'é' }), true)).toEqual({ keyKind: 'unicode', value: 0xe9 });
    expect(legacyKeyCommand(kev({ key: '€' }), true)).toEqual({ keyKind: 'unicode', value: 0x20ac });
  });

  it('named keys -> ControlKey values', () => {
    expect(legacyKeyCommand(kev({ key: 'Enter' }), true)).toEqual({ keyKind: 'control', value: ControlKey.Return });
    expect(legacyKeyCommand(kev({ key: 'Backspace' }), true)).toEqual({ keyKind: 'control', value: ControlKey.Backspace });
    expect(legacyKeyCommand(kev({ key: 'Delete' }), true)).toEqual({ keyKind: 'control', value: ControlKey.Delete });
    expect(legacyKeyCommand(kev({ key: 'Escape' }), true)).toEqual({ keyKind: 'control', value: ControlKey.Escape });
    expect(legacyKeyCommand(kev({ key: 'Tab' }), true)).toEqual({ keyKind: 'control', value: ControlKey.Tab });
    expect(legacyKeyCommand(kev({ key: 'ArrowLeft' }), true)).toEqual({ keyKind: 'control', value: ControlKey.LeftArrow });
    expect(legacyKeyCommand(kev({ key: 'ArrowUp' }), true)).toEqual({ keyKind: 'control', value: ControlKey.UpArrow });
    expect(legacyKeyCommand(kev({ key: 'F5' }), true)).toEqual({ keyKind: 'control', value: ControlKey.F5 });
    expect(legacyKeyCommand(kev({ key: 'F12' }), true)).toEqual({ keyKind: 'control', value: ControlKey.F12 });
    expect(legacyKeyCommand(kev({ key: 'PageDown' }), true)).toEqual({ keyKind: 'control', value: ControlKey.PageDown });
  });

  it('space is the Space control key, not chr 32', () => {
    expect(legacyKeyCommand(kev({ key: ' ' }), true)).toEqual({ keyKind: 'control', value: ControlKey.Space });
  });

  it('modifier keys map to left/right ControlKey variants by location', () => {
    expect(legacyKeyCommand(kev({ key: 'Shift' }), true)).toEqual({ keyKind: 'control', value: ControlKey.Shift });
    expect(legacyKeyCommand(kev({ key: 'Shift', location: 2 }), true)).toEqual({ keyKind: 'control', value: ControlKey.RShift });
    expect(legacyKeyCommand(kev({ key: 'Control', location: 2 }), true)).toEqual({ keyKind: 'control', value: ControlKey.RControl });
    expect(legacyKeyCommand(kev({ key: 'Meta' }), true)).toEqual({ keyKind: 'control', value: ControlKey.Meta });
    expect(legacyKeyCommand(kev({ key: 'Meta', location: 2 }), true)).toEqual({ keyKind: 'control', value: ControlKey.RWin });
  });

  it('numpad location maps digits and operators to Numpad* keys', () => {
    expect(legacyKeyCommand(kev({ key: '7', location: 3 }), true)).toEqual({ keyKind: 'control', value: ControlKey.Numpad7 });
    expect(legacyKeyCommand(kev({ key: '0', location: 3 }), true)).toEqual({ keyKind: 'control', value: ControlKey.Numpad0 });
    expect(legacyKeyCommand(kev({ key: '+', location: 3 }), true)).toEqual({ keyKind: 'control', value: ControlKey.Add });
    expect(legacyKeyCommand(kev({ key: '.', location: 3 }), true)).toEqual({ keyKind: 'control', value: ControlKey.Decimal });
    expect(legacyKeyCommand(kev({ key: 'Enter', location: 3 }), true)).toEqual({ keyKind: 'control', value: ControlKey.NumpadEnter });
    // same keys off the numpad stay chr
    expect(legacyKeyCommand(kev({ key: '7' }), true)).toEqual({ keyKind: 'chr', value: 55 });
  });

  it('ignores dead/IME/unmapped keys', () => {
    expect(legacyKeyCommand(kev({ key: 'Dead' }), true)).toBeNull();
    expect(legacyKeyCommand(kev({ key: 'Unidentified' }), true)).toBeNull();
    expect(legacyKeyCommand(kev({ key: 'Process' }), true)).toBeNull();
    expect(legacyKeyCommand(kev({ key: 'MediaPlayPause' }), true)).toBeNull();
  });
});
