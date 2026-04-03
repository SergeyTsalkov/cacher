# cacher2 Server Setup

This document covers setting up the cacher2 server from scratch. The server is a Node.js HTTP application backed by a local SQLite database and Cloudflare R2 for compressed package storage.

## Prerequisites

- Node.js 24+ on the host machine (uses the built-in `node:sqlite` module — no native compilation required)
- A Cloudflare R2 account (for object storage)

## Step 1 — Install dependencies

```bash
cd server
npm install
```

## Step 2 — Create R2 bucket(s)

Create one R2 bucket per world you want to configure. Worlds are isolated namespaces; most setups only need one.

In the Cloudflare dashboard: **R2 → Create bucket**. Name it something like `cacher2-main`. Repeat for each additional world.

If you also want DB backups stored in R2, create a separate bucket for that (e.g. `cacher2-backups`).

## Step 3 — Create R2 API credentials

The server needs credentials to generate presigned URLs and to upload backups.

1. Go to **Cloudflare Dashboard → R2 → Manage R2 API Tokens**
2. Click **Create API Token**
3. Set permissions to **Object Read & Write**
4. Under **Specify bucket(s)**, select all buckets from Step 2 (including the backup bucket if applicable), or leave unrestricted
5. Click **Create API Token**

Save the three values shown:
- **Access Key ID**
- **Secret Access Key** (shown only once — copy it now)
- **Account ID** (visible in the Cloudflare dashboard sidebar)

## Step 4 — Generate the root API key

The root key is level 4 and is the only credential that can create other users. Generate one now:

```bash
echo "4-$(LC_ALL=C tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 12)"
```

Keep a copy somewhere safe. If lost, generate a new one and update `config.toml`.

## Step 5 — Create config.toml

```bash
cp config.example.toml config.toml
```

Edit `config.toml`:

```toml
port = 3000
db_path = "/var/lib/cacher/cacher.db"
root_api_key = "4-xxxxxxxxxxxx"        # from Step 4

backup_bucket = "cacher2-backups"      # omit to disable backups

[r2]
account_id = "..."                     # from Step 3
access_key_id = "..."
secret_access_key = "..."

[worlds]
main = "cacher2-main"                  # world name = R2 bucket name from Step 2
```

For multiple worlds, add more entries under `[worlds]`. The first entry is the default world for root-level operations.

`config.toml` is in `.gitignore` — do not commit it.

The database file and its WAL sidecars (`-wal`, `-shm`) must be on a persistent volume. Make sure the directory exists and is writable:

```bash
mkdir -p /var/lib/cacher
```

## Step 6 — Start the server

```bash
npm start
```

The server logs its port and configured worlds on startup. To enable per-request and per-action debug logging:

```bash
npm run start:debug
```

## Step 7 — Run as a systemd service (production)

Create `/etc/systemd/system/cacher2.service`:

```ini
[Unit]
Description=cacher2 server
After=network.target

[Service]
Type=simple
User=cacher
WorkingDirectory=/opt/cacher2/server
ExecStart=/usr/bin/node /opt/cacher2/server/node_modules/.bin/tsx src/index.ts
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
```

Then:

```bash
systemctl daemon-reload
systemctl enable --now cacher2
journalctl -u cacher2 -f   # follow logs
```

## Step 8 — TLS

The server can terminate TLS directly or sit behind a reverse proxy — your choice.

**Option A — direct TLS** (add to `config.toml`):

```toml
[tls]
cert = "/etc/ssl/certs/cacher.crt"
key  = "/etc/ssl/private/cacher.key"
```

Certificates from Let's Encrypt, your CA, or self-signed all work. Omit the `[tls]` section entirely to serve plain HTTP.

**Option B — reverse proxy**

Leave `[tls]` out of `config.toml` and front the server with nginx or Caddy.

*Caddy* (`/etc/caddy/Caddyfile`):
```
cacher2.example.com {
    reverse_proxy localhost:3000
}
```

*nginx* (snippet):
```nginx
server {
    listen 443 ssl;
    server_name cacher2.example.com;
    # ... ssl config ...

    location / {
        proxy_pass http://127.0.0.1:3000;
        proxy_set_header X-Forwarded-For $remote_addr;
    }
}
```

## Step 9 — Configure PHP clients

On each machine running the cacher2 PHP client, set these constants (e.g. in your `.dev` file or environment):

```php
define('CACHER_HOME', '/path/to/local/cache');
define('CACHER2_API_URL', 'https://cacher2.example.com');
define('CACHER2_API_KEY', '4-<your-root-key>');   // or a lower-level key from Step 10
```

## Step 10 — Create non-root users (recommended)

It is best practice not to distribute the root key to regular clients. Use it once to create purpose-specific keys:

```bash
# Read/write key for CI systems
php bin/cacher2.php adduser ci-bot 2

# Read-only key for deployment machines
php bin/cacher2.php adduser deploy-node 1

# User in a specific world
php bin/cacher2.php adduser staging-bot 2 staging
```

Each command prints the generated API key. Distribute each key only to the system that needs it.

Access levels:

| Level | Permissions |
|-------|-------------|
| 1 | pull, list items |
| 2 | push, pull, list, delete items |
| 3 | level 2 + manage users (adduser/deluser/listusers within own world) |
| 4 | root — all operations across all worlds |

## Backups

If `backup_bucket` is set in `config.toml`, the server automatically takes a hot SQLite snapshot once per hour and uploads it to R2 as `cacher-YYYY-MM-DDTHH-MM-SS.db`. The snapshot uses SQLite's online backup API, so it is safe to run while the server is handling requests.

Backups accumulate — set up an R2 lifecycle rule on the backup bucket to expire objects older than your desired retention period.

To restore: download any `.db` file from the backup bucket and place it at `db_path` (stop the server first).

## Migrating from the previous MySQL-backed server

If you have an existing installation with items in a MySQL remote index, migrate the index directly into SQLite before starting the new server. **The new server's hourly cleanup job scans R2 and removes any objects not found in its database — if the database is empty when it first starts, it will delete everything in R2.** Populating SQLite first prevents this.

The migration script reads MySQL using the same `CACHER_DB_DSN`, `CACHER_DB_USER`, and `CACHER_DB_PASS` constants from your `.dev` file, and writes directly to the SQLite file. It is idempotent — safe to run multiple times.

```bash
# Dry run first — counts rows without writing anything
php bin/migrate-to-sqlite.php \
  --sqlite=/var/lib/cacher/cacher.db \
  --world=main \
  --dry-run

# Run the migration (can take a few seconds for large indexes)
php bin/migrate-to-sqlite.php \
  --sqlite=/var/lib/cacher/cacher.db \
  --world=main
```

**Recommended cutover sequence:**

1. Run the migration while the old server is still live — this gets the bulk of the data
2. Put the old server into maintenance mode (or accept a brief window of push failures)
3. Run the migration one more time to catch anything pushed since step 1
4. Start the new server (`npm start`) — by the time cleanup fires at T+5s, the DB already reflects R2
5. Update `CACHER2_API_URL` on all clients to point at the new server
6. Verify with a `remote` listing and a test pull, then decommission the old server

The R2 bucket itself does not need to change — only the index is migrated.

## Local development

```bash
cp config.example.toml config.toml
# fill in config.toml with dev credentials
npm start
# or for verbose logging:
npm run start:debug
```

The database file is created automatically at `db_path` on first run. Schema is applied automatically — no separate migration step needed.

To type-check without running:

```bash
npm run typecheck
```

## Verifying the server

```bash
# Should return {"error":"invalid key format"} — confirms the server is reachable
curl https://cacher2.example.com/items

# Should return {"items":[]} on a fresh install
curl -X POST https://cacher2.example.com/items/query \
  -H "Authorization: Bearer 4-<your-root-key>" \
  -H "Content-Type: application/json" \
  -d '{"keys":[]}'
```
