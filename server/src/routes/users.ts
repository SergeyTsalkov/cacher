import { Hono } from 'hono';
import { authMiddleware, generateApiKey, getDefaultWorld, getWorlds } from '../auth';
import type { Env } from '../index';
import type { AuthContext } from '../auth';

type Variables = { auth: AuthContext };

export const usersRouter = new Hono<{ Bindings: Env; Variables: Variables }>();

// GET /users — list all users (no API keys)
usersRouter.get('/', authMiddleware(3), async (c) => {
  const { results } = await c.env.DB.prepare(
    `SELECT name, level, world, created_at FROM users ORDER BY name`
  ).all();
  return c.json({ users: results });
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

  // Resolve world
  const worlds = getWorlds(c.env);
  let world: string;
  if (body.world) {
    if (!worlds[body.world]) {
      return c.json({ error: `unknown world: ${body.world}` }, 400);
    }
    // Level 3 can only create users in their own world
    if (auth.level === 3 && body.world !== auth.world) {
      return c.json({ error: 'level 3 users can only create users in their own world' }, 403);
    }
    world = body.world;
  } else {
    world = auth.level === 4 ? getDefaultWorld(c.env) : auth.world;
  }

  const existing = await c.env.DB.prepare(
    `SELECT 1 FROM users WHERE name = ?`
  ).bind(name).first();

  if (existing) {
    return c.json({ error: 'user already exists' }, 409);
  }

  const apiKey = generateApiKey(level as 1 | 2 | 3);

  await c.env.DB.prepare(
    `INSERT INTO users (name, api_key, level, world, created_by) VALUES (?, ?, ?, ?, ?)`
  ).bind(name, apiKey, level, world, auth.name).run();

  return c.json({ name, level, world, api_key: apiKey });
});

// DELETE /users/:name — delete a user
usersRouter.delete('/:name', authMiddleware(3), async (c) => {
  const auth = c.get('auth');
  const name = c.req.param('name');

  const target = await c.env.DB.prepare(
    `SELECT name, level FROM users WHERE name = ?`
  ).bind(name).first<{ name: string; level: number }>();

  if (!target) {
    return c.json({ error: 'user not found' }, 404);
  }
  if (target.level >= auth.level) {
    return c.json({ error: 'cannot delete user with level >= your own' }, 403);
  }

  await c.env.DB.prepare(`DELETE FROM users WHERE name = ?`).bind(name).run();

  return c.json({ ok: true });
});
