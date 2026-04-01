import { Hono } from 'hono';
import { authMiddleware } from '../auth';
import { r2ConfigForWorld, r2DeleteObject, itemKeyToObjectKey } from '../r2';
import type { Env } from '../index';
import type { AuthContext } from '../auth';

type Variables = { auth: AuthContext };

export const itemsRouter = new Hono<{ Bindings: Env; Variables: Variables }>();

// GET /items — list latest version per key
itemsRouter.get('/', authMiddleware(1), async (c) => {
  const auth = c.get('auth');
  const match = c.req.query('match');
  const exact = c.req.query('exact') === '1';

  let stmt: D1PreparedStatement;
  if (match && exact) {
    stmt = c.env.DB.prepare(
      `SELECT key, MAX(version) as version, created_at FROM items WHERE world = ? AND key = ? GROUP BY key`
    ).bind(auth.world, match);
  } else if (match) {
    stmt = c.env.DB.prepare(
      `SELECT key, MAX(version) as version, created_at FROM items WHERE world = ? AND key LIKE ? GROUP BY key ORDER BY key`
    ).bind(auth.world, match + '%');
  } else {
    stmt = c.env.DB.prepare(
      `SELECT key, MAX(version) as version, created_at FROM items WHERE world = ? GROUP BY key ORDER BY key`
    ).bind(auth.world);
  }

  const { results } = await stmt.all();
  return c.json({ items: results });
});

// GET /items/:key — all versions for a key
itemsRouter.get('/:key', authMiddleware(1), async (c) => {
  const auth = c.get('auth');
  const key = decodeURIComponent(c.req.param('key'));

  const { results } = await c.env.DB.prepare(
    `SELECT version, created_at FROM items WHERE world = ? AND key = ? ORDER BY version DESC`
  ).bind(auth.world, key).all();

  if (results.length === 0) {
    return c.json({ error: 'not found' }, 404);
  }

  return c.json({ key, versions: results });
});

// DELETE /items/:key/:version
itemsRouter.delete('/:key/:version', authMiddleware(2), async (c) => {
  const auth = c.get('auth');
  const key = decodeURIComponent(c.req.param('key'));
  const version = decodeURIComponent(c.req.param('version'));

  const existing = await c.env.DB.prepare(
    `SELECT 1 FROM items WHERE world = ? AND key = ? AND version = ?`
  ).bind(auth.world, key, version).first();

  if (!existing) {
    return c.json({ error: 'not found' }, 404);
  }

  const objectKey = itemKeyToObjectKey(key, version);
  const r2cfg = r2ConfigForWorld(c.env, auth.world);
  await r2DeleteObject(r2cfg, objectKey);

  await c.env.DB.prepare(
    `DELETE FROM items WHERE world = ? AND key = ? AND version = ?`
  ).bind(auth.world, key, version).run();

  return c.json({ ok: true });
});
