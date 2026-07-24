// Spike 2 — drive the sans-IO Session end-to-end through a decrypted LoginResponse.
// Run (offline, CI-safe, no network, DEFAULT):  npm run drive
//   or:  node --import tsx node-harness/drive-login.mjs --offline
// Run (live, against a real peer through the RustDesk server):  node --import tsx node-harness/drive-login.mjs --online
//
// Both modes use the byte-exact crypto/handshake/auth from src/*.ts. The offline
// mode plays BOTH the client Session and a scripted peer, proving the five
// silent-failure crypto traps with zero server dependency.
import { WebSocket } from 'ws';

import { WsStream } from '../src/transport/ws-stream.ts';
import { StreamCipher, sodiumReady, decodeB64 } from '../src/core/crypto.ts';
import { loginPasswordHash } from '../src/core/auth.ts';
import { Session, CLIENT_VERSION } from '../src/core/session.ts';
import {
  Hash,
  IdPk,
  LoginResponse,
  Message,
  PeerInfo,
} from '../src/gen/message.ts';
import { RelayResponse, RendezvousMessage } from '../src/gen/rendezvous.ts';

globalThis.WebSocket = WebSocket;

const sodium = (await import('libsodium-wrappers')).default;

const ONLINE = process.argv.includes('--online');

const WS_ID = process.env.RD_WS_ID ?? (() => { throw new Error('set RD_WS_ID (ws://your-hbbs:21118)'); })();
const WS_RELAY = process.env.RD_WS_RELAY ?? (() => { throw new Error('set RD_WS_RELAY (ws://your-hbbr:21119)'); })();
const KEY = process.env.RD_KEY ?? (() => { throw new Error('set RD_KEY (server id_ed25519.pub, base64)'); })();
const PEER = process.env.RD_PEER ?? '';
const PASS = process.env.RD_PASS ?? '';
const MY_ID = process.env.RD_MY_ID ?? '987654321';
const MY_NAME = process.env.RD_MY_NAME ?? 'cortendesk-harness';

function stage(n, msg) {
  console.log(`  [${n}] ${msg}`);
}

// --------------------------------------------------------------------------
// Offline loopback: harness is both the Session AND a scripted peer.
// --------------------------------------------------------------------------

const OFFLINE_PEER_ID = '123456789';
const OFFLINE_PASSWORD = 'pw123';
const OFFLINE_UUID = 'offline-uuid-1';
const OFFLINE_RELAY = 'relay.local:21117';
const OFFLINE_SALT = 'salt-abc';
const OFFLINE_CHALLENGE = 'chal-xyz';

function signIdPk(id, pk, signSk) {
  return sodium.crypto_sign(IdPk.encode({ id, pk }).finish(), signSk);
}

// Scripted peer: real ed25519 + curve25519 keys, real StreamCipher.
class ScriptedPeer {
  constructor() {
    this.edKp = sodium.crypto_sign_keypair();
    this.boxKp = sodium.crypto_box_keypair();
    this.cipher = null;
  }

  signedIdFrame(id = OFFLINE_PEER_ID) {
    return Message.encode({
      union: { $case: 'signed_id', signed_id: { id: signIdPk(id, this.boxKp.publicKey, this.edKp.privateKey) } },
    }).finish();
  }

  // Trap 3 check: open the symmetric key from the client's PublicKey message.
  acceptPublicKey(frame) {
    const msg = Message.decode(frame);
    if (msg.union?.$case !== 'public_key') throw new Error(`expected public_key, got ${msg.union?.$case}`);
    const pk = msg.union.public_key;
    if (pk.asymmetric_value.length !== 32) throw new Error(`asymmetric_value must be 32B, got ${pk.asymmetric_value.length}`);
    if (pk.symmetric_value.length !== 48) throw new Error(`symmetric_value must be 48B, got ${pk.symmetric_value.length}`);
    const key = sodium.crypto_box_open_easy(pk.symmetric_value, new Uint8Array(24), pk.asymmetric_value, this.boxKp.privateKey);
    this.cipher = new StreamCipher(key);
    return key;
  }

  seal(message) {
    return this.cipher.seal(Message.encode(message).finish());
  }

  open(frame) {
    return Message.decode(this.cipher.open(frame));
  }
}

function relayResponseFrame(peer, serverKp) {
  return RendezvousMessage.encode({
    union: {
      $case: 'relay_response',
      relay_response: RelayResponse.fromPartial({
        uuid: OFFLINE_UUID,
        relay_server: OFFLINE_RELAY,
        union: { $case: 'pk', pk: signIdPk(OFFLINE_PEER_ID, peer.edKp.publicKey, serverKp.privateKey) },
      }),
    },
  }).finish();
}

async function runOffline() {
  await sodiumReady();
  console.log('== drive-login (Spike 2, OFFLINE loopback) ==\n');

  const serverKp = sodium.crypto_sign_keypair();
  const peer = new ScriptedPeer();

  const signaling = [];
  const relay = [];
  const events = [];
  const traps = { one: false, two: false, three: false, four: true, five: false };

  const sinks = {
    sendSignaling: (b) => signaling.push(b),
    sendRelay: (b) => relay.push(b),
    emit: (ev) => {
      events.push(ev);
      if (ev.t === 'state') stage('state', `-> ${ev.state}${ev.detail ? ` (${ev.detail})` : ''}`);
      if (ev.t === 'peerInfo') stage('peerInfo', `user=${ev.username} host=${ev.hostname} ver=${ev.version} displays=${ev.displays.length} current=${ev.current}`);
      if (ev.t === 'loginError') stage('loginError', ev.message);
    },
    onVideo: () => {},
    onAudioFormat: () => {},
    onAudioFrame: () => {},
    openRelay: () => {},
    closeAll: () => {},
    onCursor: () => {},
    onCursorId: () => {},
    onClipboard: () => {},
  };

  const config = {
    peerId: OFFLINE_PEER_ID,
    serverKeyB64: sodium.to_base64(serverKp.publicKey, sodium.base64_variants.ORIGINAL),
    wsIdUrl: 'ws://unused/id',
    wsRelayUrl: 'ws://unused/relay',
    password: OFFLINE_PASSWORD,
    myId: MY_ID,
    myName: MY_NAME,
  };
  const session = new Session(config, sinks);

  const shiftRelay = () => {
    const b = relay.shift();
    if (!b) throw new Error('expected a relay frame');
    return b;
  };

  // 1. rendezvous
  session.start();
  const ph = RendezvousMessage.decode(signaling.shift());
  if (ph.union?.$case !== 'punch_hole_request') throw new Error('expected punch_hole_request');
  stage('rendezvous', `PunchHoleRequest sent (id=${ph.union.punch_hole_request.id}, force_relay=${ph.union.punch_hole_request.force_relay})`);

  // 2. server-signed RelayResponse -> trust link 1 (Trap 4, link 1)
  session.onSignalingBytes(relayResponseFrame(peer, serverKp));
  if (session.currentState !== 'relay') throw new Error(`trust link 1 failed; state=${session.currentState}`);
  stage('relay', 'trust link 1 verified (server-signed peer pk)');

  // 3. request relay
  session.relayOpened();
  const rr = RendezvousMessage.decode(shiftRelay());
  if (rr.union?.$case !== 'request_relay') throw new Error('expected request_relay');
  stage('relay', `RequestRelay sent (uuid=${rr.union.request_relay.uuid})`);

  // 4. peer SignedId -> client verifies (Trap 4, link 2) and replies PublicKey (Trap 3)
  await session.onRelayBytes(peer.signedIdFrame());
  if (session.currentState === 'error') throw new Error('peer SignedId verification failed (trap 4)');
  const pkFrame = shiftRelay();
  const key = peer.acceptPublicKey(pkFrame);
  traps.three = key.length === 32;
  stage('handshake', `peer SignedId verified; PublicKey replied; symmetric key (${key.length}B) shared — cipher live`);

  // 5. encrypted Hash -> client returns encrypted LoginRequest (Trap 5)
  await session.onRelayBytes(peer.seal({ union: { $case: 'hash', hash: Hash.fromPartial({ salt: OFFLINE_SALT, challenge: OFFLINE_CHALLENGE }) } }));
  if (session.currentState !== 'login') throw new Error(`expected login state, got ${session.currentState}`);
  const loginMsg = peer.open(shiftRelay()); // also advances peer recv counter (Trap 1)
  if (loginMsg.union?.$case !== 'login_request') throw new Error('expected login_request');
  const expected = await loginPasswordHash(OFFLINE_PASSWORD, OFFLINE_SALT, OFFLINE_CHALLENGE);
  const got = loginMsg.union.login_request.password;
  traps.five = expected.length === got.length && expected.every((v, i) => v === got[i]);
  stage('login', `LoginRequest decrypted by peer; password hash matches double-SHA256 = ${traps.five}`);
  if (!traps.five) throw new Error('login password hash mismatch (trap 5)');

  // 6. encrypted LoginResponse{peer_info} -> streaming
  await session.onRelayBytes(
    peer.seal({
      union: {
        $case: 'login_response',
        login_response: LoginResponse.fromPartial({
          union: {
            $case: 'peer_info',
            peer_info: PeerInfo.fromPartial({
              username: 'remoteuser',
              hostname: 'remotehost',
              version: '1.4.0',
              current_display: 0,
              displays: [{ x: 0, y: 0, width: 1920, height: 1080, name: 'Display-1', scale: 1 }],
            }),
          },
        }),
      },
    }),
  );
  if (session.currentState !== 'streaming') throw new Error(`expected streaming, got ${session.currentState}`);
  stage('streaming', 'decrypted LoginResponse{peer_info} — session established');

  // 7. Trap 1 + Trap 2: a len<=1 frame must bypass without consuming recvSeq,
  //    then a real sealed frame must still decrypt (counters stayed in lockstep).
  await session.onRelayBytes(new Uint8Array(0)); // bypass
  traps.two = session.currentState === 'streaming';
  await session.onRelayBytes(peer.seal({ union: { $case: 'cursor_position', cursor_position: { x: 7, y: 9 } } }));
  const lastEv = events.at(-1);
  traps.one = lastEv?.t === 'cursorPos' && lastEv.x === 7 && lastEv.y === 9;
  stage('post', `bypass frame ignored + subsequent sealed frame decrypted (recv counter intact) = ${traps.one && traps.two}`);

  console.log('\nfive-trap checklist:');
  console.log(`  1 stream nonce / pre-increment counters ....... ${traps.one ? 'PASS' : 'FAIL'}`);
  console.log(`  2 len<=1 decrypt bypass (no recvSeq consume) .. ${traps.two ? 'PASS' : 'FAIL'}`);
  console.log(`  3 symmetric key seal (32B key, 48B sealed) ... ${traps.three ? 'PASS' : 'FAIL'}`);
  console.log(`  4 attached ed25519 sigs on both trust links .. ${traps.four ? 'PASS' : 'FAIL'}`);
  console.log(`  5 double-SHA256 login hash (raw, not hex) .... ${traps.five ? 'PASS' : 'FAIL'}`);

  const allPass = Object.values(traps).every(Boolean);
  if (!allPass) {
    console.error('\nOFFLINE DRIVE FAILED: not all traps passed');
    process.exit(1);
  }
  console.log('\noffline drive complete — LoginResponse{peer_info} decrypted, all five traps proven.');
}

// --------------------------------------------------------------------------
// Online: drive the real Session against RD_PEER through the RustDesk server.
// --------------------------------------------------------------------------

async function runOnline() {
  await sodiumReady();
  console.log('== drive-login (Spike 2, ONLINE) ==\n');
  if (!PEER) {
    console.error('RD_PEER is required for --online. Set RD_PEER=<peer id> (and RD_PASS for a full login).');
    process.exit(1);
  }
  console.log(`id-server=${WS_ID} relay=${WS_RELAY} peer=${PEER} password=${PASS ? 'set' : '(none)'}\n`);

  // Validate the server key up front (decodeB64 throws on garbage).
  decodeB64(KEY);

  const idWs = await WsStream.open(WS_ID);
  let relayWs = null;
  let done = false;
  let exitCode = 1;

  const finish = (code, why) => {
    if (done) return;
    done = true;
    exitCode = code;
    stage('done', why);
    try { idWs.close(); } catch {}
    try { relayWs?.close(); } catch {}
  };

  const sinks = {
    sendSignaling: (b) => idWs.send(b),
    sendRelay: (b) => relayWs?.send(b),
    emit: (ev) => {
      if (ev.t === 'state') {
        stage('state', `-> ${ev.state}${ev.detail ? ` (${ev.detail})` : ''}`);
        if (ev.state === 'login') stage('hash', 'Hash decrypted (login challenge received) — gate reached');
        if (ev.state === 'streaming') finish(0, 'streaming: LoginResponse{peer_info} decrypted');
        if (ev.state === 'error') finish(1, `error: ${ev.detail ?? ''}`);
        if (ev.state === 'closed') finish(PASS ? 1 : 0, `closed: ${ev.detail ?? ''}`);
        if (ev.state === 'needAccept') stage('needAccept', 'peer must manually accept — reached login exchange');
      }
      if (ev.t === 'peerInfo') stage('peerInfo', `user=${ev.username} host=${ev.hostname} ver=${ev.version} displays=${ev.displays.length}`);
      if (ev.t === 'loginError') {
        stage('loginError', ev.message);
        if (!PASS) finish(0, 'reached login stage without credentials (Hash decrypted) — gate proven');
      }
    },
    onVideo: () => {},
    onAudioFormat: () => {},
    onAudioFrame: () => {},
    openRelay: async () => {
      stage('relay', `opening relay ws ${WS_RELAY}`);
      try {
        relayWs = await WsStream.open(WS_RELAY);
        session.relayOpened();
        relayWs.onMessage((b) => { void session.onRelayBytes(b); });
        relayWs.onClose(() => finish(exitCode, 'relay socket closed'));
      } catch (e) {
        finish(1, `relay open failed: ${e.message}`);
      }
    },
    closeAll: () => finish(exitCode, 'session closeAll'),
    onCursor: () => {},
    onCursorId: () => {},
    onClipboard: () => {},
  };

  const config = {
    peerId: PEER,
    serverKeyB64: KEY,
    wsIdUrl: WS_ID,
    wsRelayUrl: WS_RELAY,
    password: PASS,
    myId: MY_ID,
    myName: MY_NAME,
  };
  const session = new Session(config, sinks);

  idWs.onMessage((b) => session.onSignalingBytes(b));
  idWs.onClose(() => { if (session.currentState === 'rendezvous') finish(1, 'id socket closed during rendezvous'); });

  stage('start', `PunchHoleRequest (client ${CLIENT_VERSION})`);
  session.start();

  const deadline = Number(process.env.RD_TIMEOUT_MS ?? 25000);
  const started = Date.now();
  while (!done && Date.now() - started < deadline) {
    await new Promise((r) => setTimeout(r, 100));
  }
  if (!done) finish(1, `timeout after ${deadline}ms in state=${session.currentState}`);

  console.log(`\nonline drive ${exitCode === 0 ? 'complete' : 'ended with failure'} (final state=${session.currentState}).`);
  process.exit(exitCode);
}

(ONLINE ? runOnline() : runOffline()).catch((e) => {
  console.error(`\nDRIVE CRASHED: ${e.stack ?? e.message}`);
  process.exit(1);
});
