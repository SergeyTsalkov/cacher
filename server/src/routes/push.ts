import { Hono } from 'hono';
import { authMiddleware } from '../auth';
import { r2ConfigForWorld, presignedPutUrl, r2HeadObject, itemKeyToObjectKey } from '../r2';
import type { Env } from '../index';
import type { AuthContext } from '../auth';

type Variables = { auth: AuthContext };

export const pushRouter = new Hono<{ Bindings: Env; Variables: Variables }>();

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

  const existing = await c.env.DB.prepare(
    `SELECT 1 FROM items WHERE world = ? AND key = ? AND version = ?`
  ).bind(auth.world, key, version).first();

  if (existing) {
    return c.json({ error: 'version already exists' }, 409);
  }

  const objectKey = itemKeyToObjectKey(key, version);
  const r2cfg = r2ConfigForWorld(c.env, auth.world);
  const uploadUrl = await presignedPutUrl(r2cfg, objectKey);

  return c.json({ upload_url: uploadUrl, object_key: objectKey });
});

// POST /push/confirm — verify upload and commit to D1
pushRouter.post('/confirm', authMiddleware(2), async (c) => {
  const auth = c.get('auth');
  const body = await c.req.json<{ key: string; version: string }>();
  const { key, version } = body;

  const objectKey = itemKeyToObjectKey(key, version);
  const r2cfg = r2ConfigForWorld(c.env, auth.world);

  const exists = await r2HeadObject(r2cfg, objectKey);
  if (!exists) {
    return c.json({ error: 'object not found in R2 — upload may not have completed' }, 400);
  }

  await c.env.DB.prepare(
    `INSERT OR IGNORE INTO items (world, key, version, created_at) VALUES (?, ?, ?, unixepoch())`
  ).bind(auth.world, key, version).run();

  return c.json({ ok: true });
});
