import { Hono } from 'hono';
import { serve } from '@hono/node-server';
import { itemsRouter } from './routes/items';
import { pushRouter } from './routes/push';
import { pullRouter } from './routes/pull';
import { usersRouter } from './routes/users';
import { config } from './config';
import { log } from './logger';
import { startScheduler } from './scheduler';
import type { AuthContext } from './auth';

type Variables = { auth: AuthContext };

const app = new Hono<{ Variables: Variables }>();

app.use('*', async (c, next) => {
  log.debug(`→ ${c.req.method} ${c.req.path}`);
  await next();
  log.debug(`← ${c.res.status}`);
});

app.route('/items', itemsRouter);
app.route('/push', pushRouter);
app.route('/pull', pullRouter);
app.route('/users', usersRouter);

app.notFound(c => c.json({ error: 'not found' }, 404));
app.onError((err, c) => {
  log.error(`unhandled: ${err}`);
  return c.json({ error: 'internal server error' }, 500);
});

serve({ fetch: app.fetch, port: config.port }, () => {
  log.info(`server started on port ${config.port}`);
  log.info(`worlds: ${Object.keys(config.worlds).join(', ')}`);
  if (config.backup_bucket) {
    log.info(`backups: enabled → ${config.backup_bucket}`);
  }
});

startScheduler();
