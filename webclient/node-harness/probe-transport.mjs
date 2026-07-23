// Spike 1 — live transport probe against a real RustDesk server (dev-only, not shipped).
// Run: npm run probe   (or: node --import tsx node-harness/probe-transport.mjs)
//
// Reuses the byte-exact sans-IO core straight from src/*.ts (via tsx), so this
// exercises the SAME signaling/relay/handshake code the browser worker ships.
import { mkdirSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { WebSocket } from 'ws';

import { WsStream } from '../src/transport/ws-stream.ts';
import { buildPunchHoleRequest, parseRendezvous } from '../src/core/signaling.ts';
import { buildRequestRelay } from '../src/core/relay.ts';
import { sodiumReady, decodeB64 } from '../src/core/crypto.ts';
import { verifyServerRelayPk } from '../src/core/handshake.ts';
import { CLIENT_VERSION } from '../src/core/session.ts';
import { Message } from '../src/gen/message.ts';

globalThis.WebSocket = WebSocket;

const WS_ID = process.env.RD_WS_ID ?? (() => { throw new Error('set RD_WS_ID (ws://your-hbbs:21118)'); })();
const WS_RELAY = process.env.RD_WS_RELAY ?? (() => { throw new Error('set RD_WS_RELAY (ws://your-hbbr:21119)'); })();
const KEY = process.env.RD_KEY ?? (() => { throw new Error('set RD_KEY (server id_ed25519.pub, base64)'); })();
const PEER = process.env.RD_PEER ?? '';

const HERE = dirname(fileURLToPath(import.meta.url));
const TRANSCRIPTS = join(HERE, 'transcripts');

function hexdump(bytes) {
  const b = bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes);
  const lines = [];
  for (let i = 0; i < b.length; i += 16) {
    const chunk = b.subarray(i, i + 16);
    const hex = [...chunk].map((x) => x.toString(16).padStart(2, '0')).join(' ');
    const ascii = [...chunk].map((x) => (x >= 32 && x < 127 ? String.fromCharCode(x) : '.')).join('');
    lines.push(`${i.toString(16).padStart(8, '0')}  ${hex.padEnd(47)}  |${ascii}|`);
  }
  return lines.join('\n') || '<empty>';
}

function hexline(bytes) {
  return [...(bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes))]
    .map((x) => x.toString(16).padStart(2, '0'))
    .join('');
}

function saveTranscript(name, bytes) {
  mkdirSync(TRANSCRIPTS, { recursive: true });
  writeFileSync(join(TRANSCRIPTS, `${name}.hex`), `${hexline(bytes)}\n`);
}

function withTimeout(promise, ms, label) {
  let timer;
  const timeout = new Promise((_, reject) => {
    timer = setTimeout(() => reject(new Error(`timeout after ${ms}ms: ${label}`)), ms);
  });
  return Promise.race([promise, timeout]).finally(() => clearTimeout(timer));
}

function summarizeRendezvous(parsed) {
  switch (parsed.kind) {
    case 'relayResponse':
      return `RelayResponse uuid="${parsed.uuid}" relay_server="${parsed.relayServer}" pk=${parsed.pk ? `${parsed.pk.length}B` : 'none'}`;
    case 'punchHoleResponse':
      return `PunchHoleResponse failure=${parsed.failure ?? 'SUCCESS(socket_addr present)'}`;
    case 'onlineResponse':
      return `OnlineResponse states=${parsed.states.length}B`;
    default:
      return `other $case=${parsed.$case}`;
  }
}

// One request/response round trip. Resolves {frame} on a reply, {closed:true}
// if the server drops us first, or {error} on timeout. Never throws.
async function probeOnce(url, bytes, label, timeoutMs = 6000) {
  let ws;
  try {
    ws = await withTimeout(WsStream.open(url), timeoutMs, `open ${label}`);
  } catch (e) {
    return { openFailed: true, error: e.message };
  }
  try {
    ws.send(bytes);
    const frame = await withTimeout(ws.next(), timeoutMs, `reply ${label}`);
    return { frame };
  } catch (e) {
    if (ws.isClosed) return { closed: true };
    return { error: e.message };
  } finally {
    ws.close();
  }
}

async function main() {
  await sodiumReady();
  const serverEdPk = decodeB64(KEY);

  console.log('== CortenDesk transport probe (Spike 1) ==');
  console.log(`id-server    : ${WS_ID}`);
  console.log(`relay-server : ${WS_RELAY}`);
  console.log(`server key   : ${KEY}`);
  console.log(`peer id      : ${PEER || '(none — negative test only)'}`);
  console.log(`client ver   : ${CLIENT_VERSION}`);
  console.log('');

  let hardFailure = null;

  // ---- (a) punch hole on the id server -------------------------------------
  const probeId = PEER || '000000000';
  const phReq = buildPunchHoleRequest({ peerId: probeId, licenceKey: KEY, version: CLIENT_VERSION });
  saveTranscript('01-punch-hole-request', phReq);
  console.log(`--- (a) PunchHoleRequest -> ${WS_ID}  (id="${probeId}") ---`);
  console.log(hexdump(phReq));

  const aRes = await probeOnce(WS_ID, phReq, 'punch-hole');
  let relay = null;
  if (aRes.frame) {
    saveTranscript('02-rendezvous-reply', aRes.frame);
    console.log('\nreply:');
    console.log(hexdump(aRes.frame));
    let parsed;
    try {
      parsed = parseRendezvous(aRes.frame);
    } catch (e) {
      hardFailure = `could not parse rendezvous reply: ${e.message}`;
      parsed = null;
    }
    if (parsed) {
      console.log(`\ndecoded: ${summarizeRendezvous(parsed)}`);
      if (parsed.kind === 'relayResponse') relay = parsed;
      else if (parsed.kind === 'punchHoleResponse' && parsed.failure) {
        if (PEER) console.log(`note: peer "${PEER}" returned failure ${parsed.failure} (offline / not paired?)`);
        else console.log(`note: bogus id negative test OK — server rejected with ${parsed.failure}`);
      }
    }
  } else if (aRes.closed) {
    hardFailure = 'id server dropped the connection without a reply';
    console.log('\nresult: connection DROPPED before any reply');
  } else {
    hardFailure = aRes.error ?? 'no reply from id server';
    console.log(`\nresult: ${hardFailure}`);
  }

  // ---- (b) verify trust link 1, then request relay -------------------------
  if (relay) {
    console.log(`\n--- (b) trust link 1 + RequestRelay ---`);
    if (!relay.pk || relay.pk.length === 0) {
      hardFailure = 'RelayResponse carried no server-signed pk';
      console.log('result: FAIL — no pk in RelayResponse');
    } else {
      let peerEdPk;
      try {
        peerEdPk = verifyServerRelayPk(relay.pk, serverEdPk, PEER);
        console.log(`trust link 1 OK: server-signed IdPk verified, peer ed25519 pk = ${hexline(peerEdPk).slice(0, 16)}… (${peerEdPk.length}B)`);
      } catch (e) {
        hardFailure = `trust link 1 FAILED: ${e.message}`;
        console.log(`result: ${hardFailure}`);
      }
      if (peerEdPk) {
        const rr = buildRequestRelay({ licenceKey: KEY, peerId: PEER, uuid: relay.uuid });
        saveTranscript('03-request-relay', rr);
        console.log(`\nRequestRelay -> ${WS_RELAY}  (uuid="${relay.uuid}"):`);
        console.log(hexdump(rr));
        const bRes = await probeOnce(WS_RELAY, rr, 'request-relay', 10000);
        if (bRes.frame) {
          saveTranscript('04-first-relay-frame', bRes.frame);
          console.log('\nfirst relay frame:');
          console.log(hexdump(bRes.frame));
          try {
            const msg = Message.decode(bRes.frame);
            console.log(`\ndecoded: Message $case=${msg.union?.$case ?? 'none'}`);
            if (msg.union?.$case === 'signed_id') {
              console.log(`signed_id present (${msg.union.signed_id.id.length}B attached-signed IdPk) — handshake reachable`);
            } else {
              console.log('warning: expected signed_id as the first relay frame');
            }
          } catch (e) {
            hardFailure = `could not decode first relay frame: ${e.message}`;
            console.log(`result: ${hardFailure}`);
          }
        } else if (bRes.closed) {
          console.log('\nresult: relay dropped before the first frame (peer never joined the relay session?)');
        } else {
          console.log(`\nresult: ${bRes.error ?? 'no relay frame'}`);
        }
      }
    }
  } else if (PEER) {
    console.log('\n--- (b) skipped: no RelayResponse from step (a) ---');
  } else {
    console.log('\n--- (b) skipped: no RD_PEER set (negative test run only) ---');
  }

  // ---- (c) key posture note ------------------------------------------------
  console.log('\n--- (c) licence-key posture (correct vs wrong, both ports) ---');
  const wrongKey = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
  const posture = [];
  for (const [label, url] of [['id', WS_ID], ['relay', WS_RELAY]]) {
    for (const [keyLabel, key] of [['correct', KEY], ['wrong', wrongKey]]) {
      const bytes =
        label === 'id'
          ? buildPunchHoleRequest({ peerId: probeId, licenceKey: key, version: CLIENT_VERSION })
          : buildRequestRelay({ licenceKey: key, peerId: probeId, uuid: 'probe-uuid' });
      const r = await probeOnce(url, bytes, `${label}/${keyLabel}`, 4000);
      let outcome;
      if (r.openFailed) outcome = `open failed (${r.error})`;
      else if (r.frame) {
        let kind = 'parsed';
        try {
          kind = `parsed:${parseRendezvous(r.frame).kind}`;
        } catch {
          kind = 'reply(unparseable)';
        }
        outcome = kind;
      } else if (r.closed) outcome = 'DROPPED';
      else outcome = `no-reply (${r.error})`;
      posture.push(`  ${label.padEnd(6)} ${keyLabel.padEnd(8)} -> ${outcome}`);
    }
  }
  console.log(posture.join('\n'));
  console.log('\ninterpretation: a "DROPPED" on the wrong key while the correct key "parsed"');
  console.log('means the server gates on licence_key at the transport edge; identical');
  console.log('behaviour for both keys means the check is deferred to a later stage.');

  console.log(`\ntranscripts written to ${TRANSCRIPTS}/`);

  if (hardFailure) {
    console.error(`\nPROBE FAILED: ${hardFailure}`);
    process.exit(1);
  }
  console.log('\nprobe complete.');
}

main().catch((e) => {
  console.error(`\nPROBE CRASHED: ${e.stack ?? e.message}`);
  process.exit(1);
});
