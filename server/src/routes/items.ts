import { Hono } from 'hono';
import { authMiddleware } from '../auth';
import { r2ConfigForWorld, r2DeleteObject, itemKeyToObjectKey } from '../r2';
import { versionCompare } from '../version';
import { db } from '../db';
import { log } from '../logger';
import type { AuthContext } from '../auth';

type Variables = { auth: AuthContext };

export const itemsRouter = new Hono<{ Variables: Variables }>();

// POST /items/query — latest version for a set of keys (exact or * wildcard)
itemsRouter.post('/query', authMiddleware(1), async (c) => {
  const auth = c.get('auth');
  const body = await c.req.json<{ keys: string[] }>();
  const { keys } = body;

  if (!Array.isArray(keys)) {
    return c.json({ items: [] });
  }
  if (keys.length > 1000) {
    return c.json({ error: 'max 1000 keys per request' }, 400);
  }

  const filtered = keys.filter(k => k !== '');
  const exactKeys = filtered.filter(k => !k.includes('*'));
  const likePatterns = filtered.filter(k => k.includes('*')).map(k => k.replace(/\*/g, '%'));

  type ItemRow = { key: string; version: string; created_at: number };

  let sql = `SELECT key, version, created_at FROM items WHERE world = ?`;
  const bindings: unknown[] = [auth.world];

  if (filtered.length > 0) {
    const conditions: string[] = [];
    if (exactKeys.length > 0) {
      conditions.push(`key IN (${exactKeys.map(() => '?').join(', ')})`);
      bindings.push(...exactKeys);
    }
    for (const pattern of likePatterns) {
      conditions.push('key LIKE ?');
      bindings.push(pattern);
    }
    sql += ` AND (${conditions.join(' OR ')})`;
  }

  log.debug(`items/query: world=${auth.world} keys=${JSON.stringify(filtered)}`);

  const rows = db.prepare(sql).all(...bindings) as ItemRow[];

  const byKey = new Map<string, ItemRow>();
  for (const row of rows) {
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

  log.debug(`items/get: world=${auth.world} key=${key}`);

  const results = db.prepare(
    `SELECT version, created_at FROM items WHERE world = ? AND key = ?`
  ).all(auth.world, key) as { version: string; created_at: number }[];

  if (results.length === 0) {
    return c.json({ error: 'not found' }, 404);
  }

  const versions = [...results].sort((a, b) => versionCompare(b.version, a.version));
  return c.json({ key, versions });
});

// DELETE /items/:key/:version
itemsRouter.delete('/:key/:version', authMiddleware(2), async (c) => {
  const auth = c.get('auth');
  const key = decodeURIComponent(c.req.param('key'));
  const version = decodeURIComponent(c.req.param('version'));

  log.debug(`items/delete: world=${auth.world} key=${key} version=${version}`);

  const existing = db.prepare(
    `SELECT 1 FROM items WHERE world = ? AND key = ? AND version = ?`
  ).get(auth.world, key, version);

  if (!existing) {
    return c.json({ error: 'not found' }, 404);
  }

  const r2cfg = r2ConfigForWorld(auth.world);
  await r2DeleteObject(r2cfg, itemKeyToObjectKey(key, version));
  db.prepare(`DELETE FROM items WHERE world = ? AND key = ? AND version = ?`).run(auth.world, key, version);

  return c.json({ ok: true });
});
