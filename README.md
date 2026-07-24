# CortenDesk

<img width="100%" alt="CortenDesk - A RustDesk Pro Web GUI Application and Native Web Client" src="https://github.com/user-attachments/assets/541c6d49-8d4f-4ada-a99f-ff96d7d354d7" />

**A professional, self-hosted web console for RustDesk servers — with a fully native in-browser remote desktop client.**

CortenDesk gives the free, open-source RustDesk server (`hbbs`/`hbbr`) a clean management console: device fleet management, users and scoped access, address books, audit logs, and a web client that can view, control, and transfer files to your devices straight from the browser — no installer, no Electron, no paid tier.

Built on Laravel + Livewire with precompiled assets: **there is no frontend build step**. Clone, configure, migrate, serve.

## Features

**Console**
- **Devices** — live fleet with presence, platform icons, aliases, device groups ("folders"), pre-registration, and a recycle bin. One-click connect via `rustdesk://` deep links or the built-in web client.
- **Users & access scoping** — admins see everything; regular users see only their own devices plus device groups granted to them or their user groups. The RustDesk client API is scoped with the same rules.
- **Address books** — full support for the modern multi-address-book API *and* the legacy API: shared books, share rules (everyone / user / group), tags with colors.
- **Audit logs** — connections, file transfers, and console logins; filterable and exportable to CSV.
- **Dashboard** — live stat tiles, active sessions, 14-day connection charts, platform and version breakdowns.
- **Importer** — one artisan command migrates everything (users with passwords intact, devices, address books, audit history) from a `lejianwen/rustdesk-api` database.
- **Mobile-first** — every screen works on a phone; wide tables degrade to card lists. Dark and light themes.

**Client API**
- Implements the RustDesk client HTTP API: login/tokens, heartbeat and sysinfo presence, address books, group tab, audit ingestion. Point stock RustDesk clients at CortenDesk as their **API Server** — no client patches needed.

**Native web client**
- A from-scratch TypeScript implementation of the RustDesk wire protocol (rendezvous → relay → NaCl handshake → login), running entirely in the browser over WebSocket relays. Not a WASM port — readable, auditable source.
- Hardware-accelerated video via WebCodecs (VP8/VP9/H.264/H.265/AV1 as supported), audio, clipboard both ways, multi-monitor switching, Ctrl+Alt+Del, session stats.
- **File transfer** — an in-session dual-pane manager: browse the remote filesystem, send/receive files and folders with progress, resume-aware digests, conflict prompts, and drag-and-drop. Uses the File System Access API on Chromium; falls back to picker/Downloads elsewhere.
- Saved passwords (hashed, never plaintext) with auto-login per device.
- Best experienced in Chrome/Edge; the desktop stream requires WebCodecs.

## Requirements

- PHP **8.4+** with Composer
- MySQL/MariaDB (SQLite works for evaluation)
- nginx + php-fpm (or any Laravel-capable web server)
- A running open-source **RustDesk server** (`hbbs`/`hbbr`), reachable from your devices
- For the web client: a TLS-terminating proxy that bridges `wss://` to hbbs/hbbr WebSocket ports (21118/21119) — sample config below

## Quick start with Docker

The repo ships a self-contained image (php-fpm + nginx, web client included,
WebSocket bridge to your RustDesk server built in). Releases are published to
GHCR — or build it yourself:

```bash
docker pull ghcr.io/marcpope/cortendesk:0.8.0-beta.1   # or build locally:
docker build -t cortendesk .
docker run -d -p 8080:8080 -v cortendesk-data:/data \
  -e CORTENDESK_ID_SERVER=hbbs.example.com:21116 \
  -e CORTENDESK_RELAY_SERVER=hbbs.example.com:21117 \
  -e CORTENDESK_PUBLIC_KEY="<contents of id_ed25519.pub>" \
  cortendesk
```

First boot creates **admin / changeme** (override with `CORTENDESK_ADMIN_USER`
/ `CORTENDESK_ADMIN_PASSWORD`) and uses SQLite in the `/data` volume — see
`docker-compose.yml` for a MySQL setup. The container bridges `/ws/id` and
`/ws/relay` to your hbbs/hbbr (host taken from `CORTENDESK_ID_SERVER`, or set
`RUSTDESK_WS_HOST`). Put a TLS reverse proxy in front and set
`CORTENDESK_WS_ID_URL` / `CORTENDESK_WS_RELAY_URL` to the resulting
`wss://your-host/ws/*` URLs — browsers require wss for the web client.

## Manual installation

There is no installer — setup is a standard Laravel deployment:

```bash
git clone https://github.com/marcpope/cortendesk.git
cd cortendesk
composer install --no-dev --optimize-autoloader

cp .env.example .env
php artisan key:generate
```

Edit `.env` — the CortenDesk-specific settings:

```ini
APP_NAME=CortenDesk
APP_URL=https://console.example.com

DB_CONNECTION=mysql
DB_DATABASE=cortendesk
DB_USERNAME=cortendesk
DB_PASSWORD=********

# Your RustDesk server
CORTENDESK_ID_SERVER=hbbs.example.com:21116
CORTENDESK_RELAY_SERVER=hbbs.example.com:21117
CORTENDESK_PUBLIC_KEY=<contents of your id_ed25519.pub>

# Native web client (wss endpoints your proxy exposes, see below)
CORTENDESK_NATIVE_WEBCLIENT=true
CORTENDESK_WS_ID_URL=wss://console.example.com/ws/id
CORTENDESK_WS_RELAY_URL=wss://console.example.com/ws/relay
```

Then migrate and cache:

```bash
php artisan migrate --seed
php artisan config:cache route:cache view:cache
```

Serve `public/` with nginx + php-fpm as usual for Laravel. Log in as **admin / changeme** and change the password immediately.

### WebSocket bridge for the web client

Browsers can't open raw TCP to hbbs/hbbr, so the web client speaks WebSocket. Add to your TLS server block (adjust the upstream host if hbbs runs elsewhere):

```nginx
location = /ws/id {
    proxy_pass http://127.0.0.1:21118/;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_read_timeout 3600s;
}
location = /ws/relay {
    proxy_pass http://127.0.0.1:21119/;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_read_timeout 3600s;
}
```

### Pointing RustDesk clients at CortenDesk

In each RustDesk client (or via a mass-deployed config): **Settings → Network** — set ID Server, Relay Server, Key, and **API Server** = your console URL. Devices then appear in the console within a heartbeat (~15 s). The Settings screen shows copy-paste values for all four fields.

### Migrating from lejianwen/rustdesk-api

```bash
php artisan cortendesk:import-lejianwen /path/to/rustdeskapi.db --dry-run   # preview
php artisan cortendesk:import-lejianwen /path/to/rustdeskapi.db             # import
```

Users (original bcrypt passwords), devices (deduplicated), address books, share rules, and audit history come across. Go-encrypted address-book entry passwords cannot be decrypted and must be re-saved by users.

### Rebuilding the web client (optional)

The browser client ships prebuilt in `public/rdclient/`. To hack on it:

```bash
cd webclient
npm install
npm run build        # or: npm test / npm run typecheck
```

## License

CortenDesk is licensed under the **AGPL-3.0-only** (see `LICENSE`).

The bundled admin theme (files under `public/assets/`) is a commercial product licensed separately and is **not** covered by the AGPL — see `NOTICE`. The vendored RustDesk protocol definitions (`webclient/protos/`) are AGPL, consistent with this repository.

CortenDesk is an independent project and is not affiliated with or endorsed by RustDesk / Purslane Ltd.
