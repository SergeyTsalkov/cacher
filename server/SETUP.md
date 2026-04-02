# cacher2 Server Setup

This document covers setting up the cacher2 server component from scratch on a new Cloudflare account. The server is a Cloudflare Workers application backed by D1 (SQLite) for the item index and Cloudflare R2 for compressed package storage.

## Prerequisites

- A Cloudflare account
- Node.js 18+ installed locally
- `npm` or `npx` available
- The cacher2 PHP client installed on any machines that will push/pull packages

## Step 1 — Install dependencies

```bash
cd server
npm install
```

This installs wrangler, hono, and TypeScript locally.

## Step 2 — Authenticate wrangler

```bash
npx wrangler login
```

This opens a browser window to authorize wrangler against your Cloudflare account. Your credentials are stored locally and reused for all subsequent wrangler commands.

## Step 3 — Create the R2 bucket

Create one bucket per world you want to configure. If you are starting with a single world called `main`:

```bash
npx wrangler r2 bucket create cacher2-main
```

Repeat for any additional worlds, using a distinct bucket name each time.

Note the bucket name(s) — you will need them in Step 5.

## Step 4 — Create the D1 database

```bash
npx wrangler d1 create cacher2
```

The output will include a line like:

```
database_id = "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
```

Copy that UUID and paste it into `wrangler.toml`, replacing `REPLACE_WITH_YOUR_D1_DATABASE_ID`:

```toml
[[d1_databases]]
binding = "DB"
database_name = "cacher2"
database_id = "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"   # <-- paste here
```

## Step 5 — Configure worlds

Edit the `WORLDS` var in `wrangler.toml`. The value is a JSON object mapping world names to R2 bucket names. The first key is the default world.

```toml
[vars]
WORLDS = '{"main": "cacher2-main"}'
```

For multiple worlds:

```toml
[vars]
WORLDS = '{"main": "cacher2-main", "staging": "cacher2-staging"}'
```

World names must match `[a-zA-Z0-9]{2,14}`.

## Step 6 — Create R2 API credentials

The server needs credentials to generate presigned URLs for R2 — these are separate from your Cloudflare account login.

1. Go to **Cloudflare Dashboard → R2 → Manage R2 API Tokens**
2. Click **Create API Token**
3. Set permissions to **Object Read & Write**
4. Under **Specify bucket(s)**, select all buckets you created in Step 3 (or leave unrestricted)
5. Click **Create API Token**

Save the values shown:
- **Access Key ID** (looks like a long alphanumeric string)
- **Secret Access Key** (shown only once — copy it now)
- **Account ID** (visible in the sidebar of the Cloudflare dashboard, or in the token creation page)

## Step 7 — Set secrets

Set each secret using wrangler. You will be prompted to paste the value:

```bash
npx wrangler secret put ROOT_API_KEY
npx wrangler secret put R2_ACCOUNT_ID
npx wrangler secret put R2_ACCESS_KEY_ID
npx wrangler secret put R2_SECRET_ACCESS_KEY
```

### Generating the root API key

The root key is level 4 and is the only credential that can create other users. It is never stored in the database — only in Cloudflare Secrets.

Generate one manually using this format: a `4-` prefix followed by exactly 12 random alphanumeric characters (`[A-Za-z0-9]`). For example:

```bash
echo "4-$(LC_ALL=C tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 12)"
```

Enter the output as the value for `ROOT_API_KEY`. Keep a copy somewhere safe — it cannot be retrieved after this point. If lost, generate a new one and re-run `wrangler secret put ROOT_API_KEY`.

**For `R2_ACCOUNT_ID`:** enter your Cloudflare Account ID (32-character hex string visible in the dashboard URL or the R2 overview page).

**For `R2_ACCESS_KEY_ID` and `R2_SECRET_ACCESS_KEY`:** enter the values from Step 6.

## Step 8 — Apply the database schema

Run the schema against the remote (production) D1 instance:

```bash
npm run db:init:remote
```

This is equivalent to `wrangler d1 execute cacher2 --remote --file=schema.sql`. It creates the `items` and `users` tables. The command is safe to re-run (all statements use `CREATE TABLE IF NOT EXISTS`).

## Step 9 — Deploy

```bash
npm run deploy
```

Wrangler will bundle the TypeScript, upload it to Cloudflare, and print the Worker URL:

```
Published cacher2 (x.xx sec)
  https://cacher2.<your-subdomain>.workers.dev
```

Note this URL — it is your `CACHER2_API_URL` for PHP clients.

## Step 10 — Configure PHP clients

On each machine running the cacher2 PHP client, set these two constants (e.g. in your `.dev` file or environment):

```php
define('CACHER_HOME', '/path/to/local/cache');
define('CACHER2_API_URL', 'https://cacher2.<your-subdomain>.workers.dev');
define('CACHER2_API_KEY', '4-<your-root-key>');   // or a lower-level key from Step 11
```

Remove any old `CACHER_DB_*` and `CACHER_R2_*` constants — they are no longer used.

## Step 11 — Create non-root users (recommended)

It is best practice not to distribute the root key to regular clients. Use the root key once to create purpose-specific keys:

```bash
# Create a read/write key for CI systems
php bin/cacher2.php adduser ci-bot 2

# Create a read-only key for deployment machines
php bin/cacher2.php adduser deploy-node 1

# Create another world's user
php bin/cacher2.php adduser staging-bot 2 staging
```

Each command prints the generated API key. Distribute each key only to the system that needs it.

Access levels:
| Level | Can do |
|-------|--------|
| 1 | pull, list items |
| 2 | push, pull, list, delete items, clean |
| 3 | all of level 2 + manage users (adduser/deluser/listusers within own world) |
| 4 | root — all operations across all worlds; key lives in Cloudflare Secrets only |

## Migrating an existing MySQL remote index

If you have an existing cacher2 installation with items in a MySQL remote index, use the temporary migration tool to copy the index records into D1. The R2 bucket content does not need to change — only the index is migrated.

```bash
# Dry run first — counts rows without uploading anything
php bin/migrate-remote-db.php --dry-run

# Migrate into the default world
php bin/migrate-remote-db.php

# Or specify a world explicitly
php bin/migrate-remote-db.php --world=main
```

The tool requires `CACHER_DB_DSN`, `CACHER_DB_USER`, and `CACHER_DB_PASS` to still be defined alongside `CACHER2_API_URL` and `CACHER2_API_KEY` (root key required). The migration is idempotent — safe to re-run.

After confirming the migration looks correct, delete the temporary files:

```bash
rm bin/migrate-remote-db.php
rm server/src/routes/admin.ts
```

And remove the admin route registration from `server/src/index.ts`:

```diff
-import { adminRouter } from './routes/admin';
 ...
-app.route('/admin', adminRouter);
```

Then redeploy: `npm run deploy`.

## Local development

To run the Worker locally against a local D1 instance:

```bash
# Apply schema to local D1
npm run db:init

# Start local dev server (auto-reloads on changes)
npm run dev
```

The local server runs at `http://localhost:8787`. Secrets defined in `.dev.vars` (a file at `server/.dev.vars`, gitignored) are used in local mode:

```ini
ROOT_API_KEY=4-YourLocalRootKey
R2_ACCOUNT_ID=your-account-id
R2_ACCESS_KEY_ID=your-access-key-id
R2_SECRET_ACCESS_KEY=your-secret-access-key
```

Note: in local dev mode, R2 presigned URLs still point at the real R2 endpoint, so R2 credentials must be real even for local testing.

## Verifying the deployment

```bash
# Should return {"error":"invalid key format"} — confirms the Worker is live
curl https://cacher2.<your-subdomain>.workers.dev/items

# Should return a list of items (empty on a fresh install)
curl -H "Authorization: Bearer 4-<your-root-key>" \
  https://cacher2.<your-subdomain>.workers.dev/items
```
