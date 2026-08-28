import { describe, expect, it } from 'vitest';
import { voiceCallUiModel } from './voice-call-ui';

describe('voiceCallUiModel', () => {
  it('allows calls in any streaming session when Audio is permitted', () => {
    expect(voiceCallUiModel('streaming', true, 'idle')).toMatchObject({
      disabled: false,
      label: 'Voice call',
      status: 'Voice calls are off',
    });
  });

  it('keeps waiting and connected calls hang-up capable', () => {
    expect(voiceCallUiModel('streaming', true, 'preparing')).toMatchObject({
      disabled: true,
      label: 'Voice call',
      status: 'Requesting microphone…',
    });
    expect(voiceCallUiModel('streaming', false, 'waiting')).toMatchObject({ disabled: false, label: 'End call' });
    expect(voiceCallUiModel('closed', false, 'connected')).toMatchObject({ disabled: false, label: 'End call' });
  });

  it('explains session and Audio-permission unavailability', () => {
    expect(voiceCallUiModel('connecting', true, 'idle').status).toContain('connected session');
    expect(voiceCallUiModel('streaming', false, 'idle').status).toContain('not permitted');
  });
});
