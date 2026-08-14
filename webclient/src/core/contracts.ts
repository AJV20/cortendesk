// CortenDesk web client — CROSS-MODULE CONTRACT.
//
// SCAFFOLD-OWNED. Every module imports from this file; NO ONE else edits it.
// The shapes below are the frozen boundary between the UI (main thread) and the
// session worker, and between the sans-IO protocol core and its callers.

export type SessionConfig = { peerId:string; serverKeyB64:string; wsIdUrl:string; wsRelayUrl:string; password:string; myId:string; myName:string; savedHashHex?:string; connType?:'default'|'fileTransfer' };
export type DisplayInfo = { index:number; x:number; y:number; width:number; height:number; name:string; scale:number };
export type SessionStats = { codec:string; width:number; height:number; fps:number; mbps:number; framesDropped:number; startedAtMs:number };
export type SessionState = 'connecting'|'rendezvous'|'relay'|'handshake'|'login'|'streaming'|'error'|'closed'|'needAccept';
// File transfer: plain-object mirrors of the protobuf FileEntry/FileDirectory
// (bigints down-converted to number — file sizes/mtimes fit in 2^53).
export type FtEntryKind = 'dir'|'file'|'drive'|'dirLink'|'fileLink';
export type FtEntry = { kind:FtEntryKind; name:string; size:number; modifiedSec:number; isHidden:boolean };
export type FtDirectory = { id:number; path:string; entries:FtEntry[] };
export type SessionEvent =                       // worker -> main
  | { t:'state'; state:SessionState; detail?:string }
  | { t:'peerInfo'; displays:DisplayInfo[]; username:string; hostname:string; platform:string; version:string; current:number }
  // The peer's authoritative answer to a display switch. It is the ONLY reply
  // the host sends (server/video_service.rs make_display_changed_msg), and it
  // carries the real geometry of what is now being captured — which is what
  // input coordinates must be mapped against. A locally-assumed index is not
  // enough: the host can refuse the switch, or report different geometry.
  | { t:'switchDisplay'; index:number; x:number; y:number; width:number; height:number; cursorEmbedded:boolean }
  | { t:'stats'; stats:SessionStats }
  | { t:'cursor'; pngDataUrl:string; hotx:number; hoty:number } | { t:'cursorPos'; x:number; y:number }
  | { t:'clipboard'; text:string } | { t:'permission'; kind:string; enabled:boolean }
  | { t:'chat'; text:string }             // inbound message from the remote peer
  // Fallback video: raw H.264 Annex B forwarded for main-thread MSE playback.
  // Only emitted when WebCodecs is unavailable (insecure origin).
  | { t:'h264'; data:Uint8Array; key:boolean }
  | { t:'credentials'; hashHex:string }   // h1 = SHA256(pw||salt); UI may persist for "save password"
  | { t:'loginError'; message:string }
  | { t:'uac'; on:boolean }               // remote UAC prompt opened/closed (capture restarts around it)
  | { t:'msgbox'; msgtype:string; title:string; text:string; link:string }
  // file transfer connections only (block data arrives already zstd-decompressed):
  | { t:'ftDir'; dir:FtDirectory }
  | { t:'ftBlock'; id:number; fileNum:number; data:Uint8Array; blkId:number }
  | { t:'ftDone'; id:number; fileNum:number }
  | { t:'ftError'; id:number; fileNum:number; error:string }
  | { t:'ftDigest'; id:number; fileNum:number; lastModifiedSec:number; fileSize:number; isUpload:boolean; isIdentical:boolean; transferredSize:number; isResume:boolean }
  | { t:'ftSendConfirm'; id:number; fileNum:number; skip:boolean; offsetBytes:number } // peer confirmed our upload file (offset_blk is BYTES)
  | { t:'ftSent'; id:number; fileNum:number; buffered:number };                       // ack for an uploaded ftBlock (buffered = ws bytes queued)
export type UiCommand =                           // main -> worker
  | { c:'connect'; config:SessionConfig; canvas:OffscreenCanvas }
  | { c:'mouse'; mask:number; x:number; y:number; modifiers:number[] }
  | { c:'key'; down:boolean; press:boolean; keyKind:'chr'|'control'|'unicode'; value:number; modifiers:number[] }
  | { c:'switchDisplay'; index:number } | { c:'ctrlAltDel' } | { c:'refresh' }
  | { c:'quality'; imageQuality:number } | { c:'clipboardText'; text:string } | { c:'disconnect' }
  | { c:'remoteAudio'; enabled:boolean }
  | { c:'clipboardEnabled'; enabled:boolean }
  | { c:'clientRecording'; recording:boolean }
  | { c:'chat'; text:string }             // outbound message to the remote peer
  // file transfer connections only (no canvas; connect with config.connType='fileTransfer'):
  | { c:'connectFile'; config:SessionConfig }
  | { c:'ftReadDir'; path:string; includeHidden:boolean }
  | { c:'ftSend'; id:number; path:string; includeHidden:boolean; fileNum:number }   // ask peer to send (download)
  | { c:'ftReceive'; id:number; path:string; files:{ name:string; size:number; modifiedSec:number }[]; totalSize:number; fileNum:number } // announce upload
  | { c:'ftDigest'; id:number; fileNum:number; fileSize:number; lastModifiedSec:number } // upload: announce source file, wait for confirm
  | { c:'ftBlock'; id:number; fileNum:number; data:Uint8Array; blkId:number }       // upload payload chunk (empty data = EOF marker)
  | { c:'ftDone'; id:number; fileNum:number }                                       // upload: whole job complete (fileNum = file count)
  | { c:'ftError'; id:number; fileNum:number; error:string }
  | { c:'ftConfirm'; id:number; fileNum:number; skip:boolean; offsetBlk:number }    // reply to ftDigest
  | { c:'ftCancel'; id:number }
  | { c:'ftCreateDir'; id:number; path:string }
  | { c:'ftRemoveFile'; id:number; path:string; fileNum:number }
  | { c:'ftRemoveDir'; id:number; path:string }
  | { c:'ftRename'; id:number; path:string; newName:string };
export interface Transport { send(bytes:Uint8Array):void; onMessage(cb:(b:Uint8Array)=>void):void; onClose(cb:()=>void):void; close():void; buffered?():number; }
export interface Encryptor { seal(pt:Uint8Array):Uint8Array; open(ct:Uint8Array):Uint8Array; }
