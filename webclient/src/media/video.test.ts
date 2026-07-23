import { describe, it, expect, vi, afterEach } from 'vitest';
import { probeSupportedDecoding, VideoPipeline } from './video';
import { SupportedDecoding_PreferCodec, VideoFrame } from '../gen/message';
import type { SessionStats } from '../core/contracts';

type StubFrame = { displayWidth: number; displayHeight: number; close: () => void };
type OutputCb = (frame: StubFrame) => void;
type ErrorCb = (e: unknown) => void;

class StubChunk {
  type: string;
  timestamp: number;
  data: Uint8Array;
  constructor(init: { type: string; timestamp: number; data: Uint8Array }) {
    this.type = init.type;
    this.timestamp = init.timestamp;
    this.data = init.data;
  }
}

function makeStubDecoder(opts: { supported?: string[]; failDecode?: boolean } = {}) {
  const instances: InstanceType<typeof StubVideoDecoder>[] = [];
  const chunks: StubChunk[] = [];
  class StubVideoDecoder {
    static async isConfigSupported(cfg: { codec: string }) {
      return { supported: (opts.supported ?? []).includes(cfg.codec) };
    }
    state = 'unconfigured';
    output: OutputCb;
    error: ErrorCb;
    constructor(init: { output: OutputCb; error: ErrorCb }) {
      this.output = init.output;
      this.error = init.error;
      instances.push(this);
    }
    configure(_cfg: unknown) {
      this.state = 'configured';
    }
    decode(chunk: StubChunk) {
      chunks.push(chunk);
      if (opts.failDecode) throw new Error('decode failed');
      this.output({ displayWidth: 640, displayHeight: 480, close: () => {} });
    }
    close() {
      this.state = 'closed';
    }
  }
  return { StubVideoDecoder, instances, chunks };
}

function stubGlobals(dec: unknown) {
  vi.stubGlobal('VideoDecoder', dec);
  vi.stubGlobal('EncodedVideoChunk', StubChunk);
}

function makeCanvas() {
  const ctx = { drawImage: vi.fn() };
  const canvas = { width: 0, height: 0, getContext: vi.fn(() => ctx) };
  return { canvas: canvas as unknown as OffscreenCanvas, ctx };
}

function vp9Frame(keys: boolean[], bytesPer = 3): VideoFrame {
  return VideoFrame.fromPartial({
    union: {
      $case: 'vp9s',
      vp9s: {
        frames: keys.map((key, i) => ({
          data: new Uint8Array(bytesPer).fill(i + 1),
          key,
          pts: BigInt(i * 16),
        })),
      },
    },
    display: 0,
  });
}

function makePipeline() {
  const { canvas, ctx } = makeCanvas();
  const onAck = vi.fn();
  const onNeedRefresh = vi.fn();
  const onStats = vi.fn<(s: Partial<SessionStats>) => void>();
  const pipe = new VideoPipeline(canvas, onAck, onNeedRefresh, onStats);
  return { pipe, canvas, ctx, onAck, onNeedRefresh, onStats };
}

afterEach(() => {
  vi.unstubAllGlobals();
  vi.useRealTimers();
});

describe('probeSupportedDecoding (stubbed)', () => {
  it('sets abilities only for codecs that probe ok, never h265', async () => {
    const { StubVideoDecoder } = makeStubDecoder({
      supported: ['vp09.00.10.08', 'vp8', 'hev1.1.6.L93.B0'],
    });
    stubGlobals(StubVideoDecoder);
    const sd = await probeSupportedDecoding();
    expect(sd.ability_vp9).toBe(1);
    expect(sd.ability_vp8).toBe(1);
    expect(sd.ability_h264).toBe(0);
    expect(sd.ability_av1).toBe(0);
    expect(sd.ability_h265).toBe(0); // stays off even when the probe passes
    expect(sd.prefer).toBe(SupportedDecoding_PreferCodec.Auto);
    expect(sd.i444).toBeUndefined();
    expect(sd.prefer_chroma).toBe(0); // I420 default, never set explicitly
  });

  it('returns all-zero abilities when VideoDecoder is unavailable', async () => {
    expect(typeof VideoDecoder).toBe('undefined');
    const sd = await probeSupportedDecoding();
    expect(sd.ability_vp9).toBe(0);
    expect(sd.ability_vp8).toBe(0);
    expect(sd.ability_h264).toBe(0);
    expect(sd.ability_h265).toBe(0);
    expect(sd.ability_av1).toBe(0);
  });
});

describe('VideoPipeline gating and drawing (stubbed)', () => {
  it('drops delta frames before the first keyframe and asks for a refresh', () => {
    const { StubVideoDecoder, chunks } = makeStubDecoder();
    stubGlobals(StubVideoDecoder);
    const { pipe, onAck, onNeedRefresh } = makePipeline();
    pipe.pushFrame(vp9Frame([false]));
    expect(chunks).toHaveLength(0);
    expect(onAck).not.toHaveBeenCalled();
    expect(onNeedRefresh).toHaveBeenCalledTimes(1);
    pipe.pushFrame(vp9Frame([true, false]));
    expect(chunks.map((c) => c.type)).toEqual(['key', 'delta']);
    expect(onAck).toHaveBeenCalledTimes(2);
    pipe.close();
  });

  it('debounces refresh requests to at most one per second', () => {
    const { StubVideoDecoder } = makeStubDecoder();
    stubGlobals(StubVideoDecoder);
    vi.useFakeTimers();
    const { pipe, onNeedRefresh } = makePipeline();
    pipe.pushFrame(vp9Frame([false]));
    pipe.pushFrame(vp9Frame([false]));
    vi.advanceTimersByTime(500);
    pipe.pushFrame(vp9Frame([false]));
    expect(onNeedRefresh).toHaveBeenCalledTimes(1);
    vi.advanceTimersByTime(600);
    pipe.pushFrame(vp9Frame([false]));
    expect(onNeedRefresh).toHaveBeenCalledTimes(2);
    pipe.close();
  });

  it('converts pts ms to microsecond chunk timestamps and draws to the canvas', () => {
    const { StubVideoDecoder, chunks } = makeStubDecoder();
    stubGlobals(StubVideoDecoder);
    const { pipe, canvas, ctx } = makePipeline();
    pipe.pushFrame(vp9Frame([true, false]));
    expect(chunks[0]!.timestamp).toBe(0);
    expect(chunks[1]!.timestamp).toBe(16_000);
    expect((canvas as unknown as { width: number }).width).toBe(640);
    expect((canvas as unknown as { height: number }).height).toBe(480);
    expect(ctx.drawImage).toHaveBeenCalledTimes(2);
    pipe.close();
  });

  it('disables a codec after MAX_DECODE_FAIL consecutive failures and re-advertises', () => {
    const { StubVideoDecoder, instances } = makeStubDecoder({ failDecode: true });
    stubGlobals(StubVideoDecoder);
    vi.useFakeTimers();
    const { pipe, onNeedRefresh } = makePipeline();
    const readv = vi.fn();
    pipe.onNeedReadvertise = readv;
    pipe.pushFrame(vp9Frame([true]));
    vi.advanceTimersByTime(1100);
    pipe.pushFrame(vp9Frame([true]));
    vi.advanceTimersByTime(1100);
    expect(readv).not.toHaveBeenCalled();
    pipe.pushFrame(vp9Frame([true]));
    expect(readv).toHaveBeenCalledTimes(1);
    expect(readv).toHaveBeenCalledWith('vp9s');
    expect(pipe.disabledCodecs()).toEqual(['vp9s']);
    expect(instances.at(-1)!.state).toBe('closed');
    const before = instances.length;
    vi.advanceTimersByTime(1100);
    pipe.pushFrame(vp9Frame([true]));
    expect(instances.length).toBe(before); // disabled codec never reconfigures
    expect(onNeedRefresh.mock.calls.length).toBeGreaterThanOrEqual(3);
    pipe.close();
  });

  it('reset closes the decoder and requires a fresh keyframe', () => {
    const { StubVideoDecoder, instances, chunks } = makeStubDecoder();
    stubGlobals(StubVideoDecoder);
    const { pipe } = makePipeline();
    pipe.pushFrame(vp9Frame([true]));
    expect(instances).toHaveLength(1);
    pipe.reset();
    expect(instances[0]!.state).toBe('closed');
    pipe.pushFrame(vp9Frame([false])); // delta after reset: dropped, no new decode
    expect(chunks).toHaveLength(1);
    pipe.pushFrame(vp9Frame([true]));
    expect(instances).toHaveLength(2);
    expect(chunks).toHaveLength(2);
    pipe.close();
  });

  it('emits stats once per interval with fps, mbps and codec name', () => {
    const { StubVideoDecoder } = makeStubDecoder();
    stubGlobals(StubVideoDecoder);
    vi.useFakeTimers();
    const { pipe, onStats } = makePipeline();
    pipe.pushFrame(vp9Frame([true], 125_000));
    expect(onStats).not.toHaveBeenCalled();
    vi.advanceTimersByTime(1000);
    pipe.pushFrame(vp9Frame([false], 125_000));
    expect(onStats).toHaveBeenCalledTimes(1);
    const s = onStats.mock.calls[0]![0];
    expect(s.codec).toBe('vp9');
    expect(s.width).toBe(640);
    expect(s.height).toBe(480);
    expect(s.fps).toBe(2);
    expect(s.mbps).toBe(2); // 2 * 125000 B * 8 over 1s = 2 Mbps
    expect(s.framesDropped).toBe(0);
    pipe.close();
  });

  it('ignores rgb/yuv unions and pushFrame is a no-op without WebCodecs', () => {
    const { pipe, onAck } = makePipeline();
    pipe.pushFrame(vp9Frame([true])); // no VideoDecoder global: must not throw
    expect(onAck).not.toHaveBeenCalled();
    const { StubVideoDecoder, instances } = makeStubDecoder();
    stubGlobals(StubVideoDecoder);
    pipe.pushFrame(
      VideoFrame.fromPartial({ union: { $case: 'rgb', rgb: { compress: false } }, display: 0 }),
    );
    expect(instances).toHaveLength(0);
    pipe.close();
  });
});

describe.skipIf(typeof VideoDecoder === 'undefined')('probeSupportedDecoding (real WebCodecs)', () => {
  it('returns a plausible SupportedDecoding', async () => {
    const sd = await probeSupportedDecoding();
    for (const v of [sd.ability_vp9, sd.ability_vp8, sd.ability_h264, sd.ability_av1]) {
      expect([0, 1]).toContain(v);
    }
    expect(sd.ability_h265).toBe(0);
    expect(sd.prefer).toBe(SupportedDecoding_PreferCodec.Auto);
    expect(sd.i444).toBeUndefined();
  });
});
