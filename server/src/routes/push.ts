import { Hono } from 'hono';
import { authMiddleware } from '../auth';
import { r2ConfigForWorld, presignedPutUrl, r2HeadObject, itemKeyToObjectKey } from '../r2';
import { db } from '../db';
import { log } from '../logger';
import type { AuthContext } from '../auth';

type Variables = { auth: AuthContext };

export const pushRouter = new Hono<{ Variables: Variables }>();

// POST /push/init — register push intent, get presigned upload URL
pushRouter.post('/init', authMiddleware(2), async (c) => {
  const auth = c.get('auth');
  const body = await c.req.json<{ key: string; version: string }>();
  const { key, version } = body;

  if (!key || !/^[\w\-:]+$/i.test(key)) {
    return c.json({ error: 'invalid key' }, 400);
  }
  if (!version) {
    return c.json({ error: 'version required' }, 400);
  }

  log.debug(`push/init: world=${auth.world} key=${key} version=${version}`);

  const existing = db.prepare(
    `SELECT 1 FROM items WHERE world = ? AND key = ? AND version = ?`
  ).get(auth.world, key, version);

  if (existing) {
    return c.json({ error: 'version already exists' }, 409);
  }

  const objectKey = itemKeyToObjectKey(key, version);
  const uploadUrl = await presignedPutUrl(r2ConfigForWorld(auth.world), objectKey);

  return c.json({ upload_url: uploadUrl, object_key: objectKey });
});

// POST /push/confirm — verify upload landed in R2, then commit to DB
pushRouter.post('/confirm', authMiddleware(2), async (c) => {
  const auth = c.get('auth');
  const body = await c.req.json<{ key: string; version: string }>();
  const { key, version } = body;

  log.debug(`push/confirm: world=${auth.world} key=${key} version=${version}`);

  const objectKey = itemKeyToObjectKey(key, version);
  const exists = await r2HeadObject(r2ConfigForWorld(auth.world), objectKey);
  if (!exists) {
    return c.json({ error: 'object not found in R2 — upload may not have completed' }, 400);
  }

  db.prepare(
    `INSERT OR IGNORE INTO items (world, key, version, created_at) VALUES (?, ?, ?, unixepoch())`
  ).run(auth.world, key, version);

  return c.json({ ok: true });
});
