// TEMPORARY: Migration endpoints — delete this file after migration to D1 is complete.
// Also remove the admin route from src/index.ts.

import { Hono } from 'hono';
import { authMiddleware, getDefaultWorld, getWorlds } from '../auth';
import type { Env } from '../index';
import type { AuthContext } from '../auth';

type Variables = { auth: AuthContext };

export const adminRouter = new Hono<{ Bindings: Env; Variables: Variables }>();

interface MigrateItem {
  key: string;
  version: string;
  created_at: number;
}

// GET /admin/worlds — list configured worlds (used by migration tool)
adminRouter.get('/worlds', authMiddleware(4), async (c) => {
  const worlds = getWorlds(c.env);
  const worldNames = Object.keys(worlds);
  return c.json({ worlds: worldNames, default: worldNames[0] ?? null });
});

// POST /admin/migrate — bulk insert items (idempotent)
adminRouter.post('/migrate', authMiddleware(4), async (c) => {
  const body = await c.req.json<{ world?: string; items: MigrateItem[] }>();
  const { items } = body;

  if (!Array.isArray(items) || items.length === 0) {
    return c.json({ error: 'items array required' }, 400);
  }
  if (items.length > 500) {
    return c.json({ error: 'max 500 items per batch' }, 400);
  }

  const worlds = getWorlds(c.env);
  const world = body.world ?? Object.keys(worlds)[0];
  if (!world || !worlds[world]) {
    return c.json({ error: `unknown world: ${world}` }, 400);
  }

  let inserted = 0;
  let skipped = 0;

  for (const item of items) {
    if (!item.key || !item.version) { skipped++; continue; }

    const result = await c.env.DB.prepare(
      `INSERT OR IGNORE INTO items (world, key, version, created_at) VALUES (?, ?, ?, ?)`
    ).bind(world, item.key, item.version, item.created_at ?? Math.floor(Date.now() / 1000)).run();

    if (result.meta.changes > 0) inserted++;
    else skipped++;
  }

  return c.json({ inserted, skipped });
});
