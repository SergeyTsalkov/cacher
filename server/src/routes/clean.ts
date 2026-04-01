import { Hono } from 'hono';
import { authMiddleware } from '../auth';
import { r2ConfigForWorld, r2DeleteObject, itemKeyToObjectKey } from '../r2';
import type { Env } from '../index';
import type { AuthContext } from '../auth';

type Variables = { auth: AuthContext };

export const cleanRouter = new Hono<{ Bindings: Env; Variables: Variables }>();

interface ItemRow {
  key: string;
  version: string;
  created_at: number;
}

// POST /clean — purge old settled versions
cleanRouter.post('/', authMiddleware(2), async (c) => {
  const auth = c.get('auth');
  const purgeAfter = 60 * 60 * 24; // 24 hours
  const now = Math.floor(Date.now() / 1000);

  const { results } = await c.env.DB.prepare(
    `SELECT key, version, created_at FROM items WHERE world = ? ORDER BY key, version DESC`
  ).bind(auth.world).all<ItemRow>();

  // Group versions by key
  const byKey = new Map<string, ItemRow[]>();
  for (const row of results) {
    if (!byKey.has(row.key)) byKey.set(row.key, []);
    byKey.get(row.key)!.push(row);
  }

  const toDelete: ItemRow[] = [];

  for (const [, versions] of byKey) {
    // versions are already sorted newest-first (ORDER BY version DESC)
    // Find the newest "settled" version (age >= 24h)
    let settledVersion: string | null = null;
    for (const v of versions) {
      if (now - v.created_at >= purgeAfter) {
        settledVersion = v.version;
        break;
      }
    }

    if (!settledVersion) continue;

    // Mark all versions older than settled as old
    for (const v of versions) {
      if (v.version < settledVersion) {
        toDelete.push(v);
      }
    }
  }

  const r2cfg = r2ConfigForWorld(c.env, auth.world);
  const deleted: string[] = [];

  for (const item of toDelete) {
    const objectKey = itemKeyToObjectKey(item.key, item.version);
    await r2DeleteObject(r2cfg, objectKey);
    await c.env.DB.prepare(
      `DELETE FROM items WHERE world = ? AND key = ? AND version = ?`
    ).bind(auth.world, item.key, item.version).run();
    deleted.push(`${item.key}@${item.version}`);
  }

  return c.json({ deleted });
});
