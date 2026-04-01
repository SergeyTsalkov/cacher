import { Hono } from 'hono';
import { authMiddleware } from '../auth';
import { r2ConfigForWorld, r2DeleteObject, itemKeyToObjectKey } from '../r2';
import { versionCompare, latestVersion } from '../version';
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
      `SELECT key, version, created_at FROM items WHERE world = ? AND key = ?`
    ).bind(auth.world, match);
  } else if (match) {
    stmt = c.env.DB.prepare(
      `SELECT key, version, created_at FROM items WHERE world = ? AND key LIKE ?`
    ).bind(auth.world, match + '%');
  } else {
    stmt = c.env.DB.prepare(
      `SELECT key, version, created_at FROM items WHERE world = ?`
    ).bind(auth.world);
  }

  const { results } = await stmt.all<{ key: string; version: string; created_at: number }>();

  // Group by key and pick the latest version per key using proper version comparison
  const byKey = new Map<string, { key: string; version: string; created_at: number }>();
  for (const row of results) {
    const current = byKey.get(row.key);
    if (!current || versionCompare(row.version, current.version) > 0) {
      byKey.set(row.key, row);
    }
  }

  const items = [...byKey.values()].sort((a, b) => a.key.localeCompare(b.key));
  return c.json({ items });
});

// GET /items/:key — all versions for a key, newest first
itemsRouter.get('/:key', authMiddleware(1), async (c) => {
  const auth = c.get('auth');
  const key = decodeURIComponent(c.req.param('key'));

  const { results } = await c.env.DB.prepare(
    `SELECT version, created_at FROM items WHERE world = ? AND key = ?`
  ).bind(auth.world, key).all<{ version: string; created_at: number }>();

  if (results.length === 0) {
    return c.json({ error: 'not found' }, 404);
  }

  const versions = [...results].sort((a, b) => versionCompare(b.version, a.version));
  return c.json({ key, versions });
});

// POST /items/batch — fetch latest version for a specific set of keys (single round-trip)
itemsRouter.post('/batch', authMiddleware(1), async (c) => {
  const auth = c.get('auth');
  const body = await c.req.json<{ keys: string[] }>();
  const { keys } = body;

  if (!Array.isArray(keys) || keys.length === 0) {
    return c.json({ items: [] });
  }
  if (keys.length > 1000) {
    return c.json({ error: 'max 1000 keys per batch' }, 400);
  }

  const placeholders = keys.map(() => '?').join(', ');
  const { results } = await c.env.DB.prepare(
    `SELECT key, version, created_at FROM items WHERE world = ? AND key IN (${placeholders})`
  ).bind(auth.world, ...keys).all<{ key: string; version: string; created_at: number }>();

  // Pick the latest version per key using proper version comparison
  const byKey = new Map<string, { key: string; version: string; created_at: number }>();
  for (const row of results) {
    const current = byKey.get(row.key);
    if (!current || versionCompare(row.version, current.version) > 0) {
      byKey.set(row.key, row);
    }
  }

  return c.json({ items: [...byKey.values()] });
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
