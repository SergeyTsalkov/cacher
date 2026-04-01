import { Hono } from 'hono';
import { authMiddleware } from '../auth';
import { r2ConfigForWorld, presignedGetUrl, itemKeyToObjectKey } from '../r2';
import { latestVersion } from '../version';
import type { Env } from '../index';
import type { AuthContext } from '../auth';

type Variables = { auth: AuthContext };

export const pullRouter = new Hono<{ Bindings: Env; Variables: Variables }>();

// POST /pull — get latest version metadata + presigned download URL
pullRouter.post('/', authMiddleware(1), async (c) => {
  const auth = c.get('auth');
  const body = await c.req.json<{ key: string }>();
  const { key } = body;

  if (!key) {
    return c.json({ error: 'key required' }, 400);
  }

  const { results } = await c.env.DB.prepare(
    `SELECT version, created_at FROM items WHERE world = ? AND key = ?`
  ).bind(auth.world, key).all<{ version: string; created_at: number }>();

  const row = latestVersion(results);

  if (!row) {
    return c.json({ error: 'not found' }, 404);
  }

  const objectKey = itemKeyToObjectKey(key, row.version);
  const r2cfg = r2ConfigForWorld(c.env, auth.world);
  const downloadUrl = await presignedGetUrl(r2cfg, objectKey);

  return c.json({
    key,
    version: row.version,
    created_at: row.created_at,
    download_url: downloadUrl,
    object_key: objectKey,
  });
});
