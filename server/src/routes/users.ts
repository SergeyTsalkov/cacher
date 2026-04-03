import { Hono } from 'hono';
import { authMiddleware, generateApiKey } from '../auth';
import { config } from '../config';
import { db } from '../db';
import { log } from '../logger';
import type { AuthContext } from '../auth';

type Variables = { auth: AuthContext };

export const usersRouter = new Hono<{ Variables: Variables }>();

// GET /users — list all users (no API keys)
usersRouter.get('/', authMiddleware(3), async (c) => {
  log.debug('users/list');
  const users = db.prepare(
    `SELECT name, level, world, created_at FROM users ORDER BY name`
  ).all();
  return c.json({ users });
});

// POST /users — create a user
usersRouter.post('/', authMiddleware(3), async (c) => {
  const auth = c.get('auth');
  const body = await c.req.json<{ name: string; level: number; world?: string }>();
  const { name, level } = body;

  if (!name || !/^[a-zA-Z0-9_\-]{1,64}$/.test(name)) {
    return c.json({ error: 'invalid name' }, 400);
  }
  if (!level || ![1, 2, 3].includes(level)) {
    return c.json({ error: 'level must be 1, 2, or 3' }, 400);
  }
  if (level >= auth.level) {
    return c.json({ error: 'cannot create user with level >= your own' }, 403);
  }

  let world: string;
  if (body.world) {
    if (!config.worlds[body.world]) {
      return c.json({ error: `unknown world: ${body.world}` }, 400);
    }
    if (auth.level === 3 && body.world !== auth.world) {
      return c.json({ error: 'level 3 users can only create users in their own world' }, 403);
    }
    world = body.world;
  } else {
    world = auth.level === 4 ? Object.keys(config.worlds)[0] : auth.world;
  }

  log.debug(`users/create: name=${name} level=${level} world=${world} by=${auth.name}`);

  const existing = db.prepare(`SELECT 1 FROM users WHERE name = ?`).get(name);
  if (existing) {
    return c.json({ error: 'user already exists' }, 409);
  }

  const apiKey = generateApiKey(level as 1 | 2 | 3);
  db.prepare(
    `INSERT INTO users (name, api_key, level, world, created_by) VALUES (?, ?, ?, ?, ?)`
  ).run(name, apiKey, level, world, auth.name);

  return c.json({ name, level, world, api_key: apiKey });
});

// DELETE /users/:name
usersRouter.delete('/:name', authMiddleware(3), async (c) => {
  const auth = c.get('auth');
  const name = c.req.param('name');

  log.debug(`users/delete: name=${name} by=${auth.name}`);

  const target = db.prepare(
    `SELECT name, level FROM users WHERE name = ?`
  ).get(name) as { name: string; level: number } | undefined;

  if (!target) {
    return c.json({ error: 'user not found' }, 404);
  }
  if (target.level >= auth.level) {
    return c.json({ error: 'cannot delete user with level >= your own' }, 403);
  }

  db.prepare(`DELETE FROM users WHERE name = ?`).run(name);
  return c.json({ ok: true });
});
