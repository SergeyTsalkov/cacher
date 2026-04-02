import { createMiddleware } from 'hono/factory';
import type { Env } from './index';

export interface AuthContext {
  level: number;
  name: string;
  world: string;
  key: string;
}

export function parseKeyFormat(key: string): { level: number; token: string } | null {
  const m = key.match(/^([1-4])-([A-Za-z0-9]{12})$/);
  if (!m) return null;
  return { level: parseInt(m[1]), token: m[2] };
}

export function generateApiKey(level: 1 | 2 | 3): string {
  const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
  const bytes = crypto.getRandomValues(new Uint8Array(12));
  const token = Array.from(bytes).map(b => chars[b % chars.length]).join('');
  return `${level}-${token}`;
}

export function getWorlds(env: Env): Record<string, string> {
  return JSON.parse(env.WORLDS) as Record<string, string>;
}

export function getDefaultWorld(env: Env): string {
  const worlds = getWorlds(env);
  const first = Object.keys(worlds)[0];
  if (!first) throw new Error('No worlds configured in WORLDS');
  return first;
}

type Variables = { auth: AuthContext };

export const authMiddleware = (minLevel: number) =>
  createMiddleware<{ Bindings: Env; Variables: Variables }>(async (c, next) => {
    const header = c.req.header('Authorization') ?? '';
    const key = header.replace(/^Bearer\s+/, '');

    const parsed = parseKeyFormat(key);
    if (!parsed) return c.json({ error: 'invalid key format' }, 401);

    let auth: AuthContext;
    const worlds = getWorlds(c.env);

    if (parsed.level === 4) {
      if (key !== c.env.ROOT_API_KEY) {
        return c.json({ error: 'unauthorized' }, 401);
      }
      const defaultWorld = Object.keys(worlds)[0];
      if (!defaultWorld) return c.json({ error: 'no worlds configured' }, 500);
      auth = { level: 4, name: 'root', world: defaultWorld, key };
    } else {
      const row = await c.env.DB.prepare(
        'SELECT name, level, world FROM users WHERE api_key = ?'
      ).bind(key).first<{ name: string; level: number; world: string }>();

      if (!row) return c.json({ error: 'unauthorized' }, 401);
      if (!worlds[row.world]) {
        return c.json({ error: `world '${row.world}' is no longer configured` }, 403);
      }
      auth = { level: row.level, name: row.name, world: row.world, key };
    }

    if (auth.level < minLevel) {
      return c.json({ error: 'insufficient access level' }, 403);
    }

    c.set('auth', auth);
    await next();
  });
