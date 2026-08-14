import { describe, expect, it } from 'vitest';
import type { SessionConfig } from '../core/contracts';
import { buildCameraConfig } from './camera-panel';

it('creates an isolated view-camera session without changing the source config', () => {
  const source: SessionConfig = {
    peerId: '123', serverKeyB64: 'key', wsIdUrl: 'wss://id', wsRelayUrl: 'wss://relay',
    password: '', savedHashHex: 'hash', myId: 'browser', myName: 'Browser', connType: 'default',
  };
  const camera = buildCameraConfig(source);
  expect(camera.connType).toBe('viewCamera');
  expect(camera.savedHashHex).toBe('hash');
  expect(source.connType).toBe('default');
});
