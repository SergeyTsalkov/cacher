import { createMiddleware } from 'hono/factory';
import { randomBytes } from 'crypto';
import { config } from './config';
import { db } from './db';
import { log } from './logger';

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
  const bytes = randomBytes(12);
  const token = Array.from(bytes).map(b => chars[b % chars.length]).join('');
  return `${level}-${token}`;
}

type Variables = { auth: AuthContext };

export const authMiddleware = (minLevel: number) =>
  createMiddleware<{ Variables: Variables }>(async (c, next) => {
    const header = c.req.header('Authorization') ?? '';
    const key = header.replace(/^Bearer\s+/, '');
    const ip = c.req.header('X-Forwarded-For') ?? 'unknown';

    const parsed = parseKeyFormat(key);
    if (!parsed) {
      log.warn(`auth: invalid key format from ${ip}`);
      return c.json({ error: 'invalid key format' }, 401);
    }

    let auth: AuthContext;

    if (parsed.level === 4) {
      if (key !== config.root_api_key) {
        log.warn(`auth: invalid root key from ${ip}`);
        return c.json({ error: 'unauthorized' }, 401);
      }
      const defaultWorld = Object.keys(config.worlds)[0];
      if (!defaultWorld) return c.json({ error: 'no worlds configured' }, 500);
      auth = { level: 4, name: 'root', world: defaultWorld, key };
    } else {
      const row = db.prepare(
        'SELECT name, level, world FROM users WHERE api_key = ?'
      ).get(key) as { name: string; level: number; world: string } | undefined;

      if (!row) {
        log.warn(`auth: unknown api key from ${ip}`);
        return c.json({ error: 'unauthorized' }, 401);
      }
      if (!config.worlds[row.world]) {
        return c.json({ error: `world '${row.world}' is no longer configured` }, 403);
      }
      auth = { level: row.level, name: row.name, world: row.world, key };
    }

    if (auth.level < minLevel) {
      log.warn(`auth: ${auth.name} (level ${auth.level}) needs level ${minLevel} for ${c.req.method} ${c.req.path}`);
      return c.json({ error: 'insufficient access level' }, 403);
    }

    c.set('auth', auth);
    await next();
  });
