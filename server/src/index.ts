import { Hono } from 'hono';
import { itemsRouter } from './routes/items';
import { pushRouter } from './routes/push';
import { pullRouter } from './routes/pull';
import { cleanRouter } from './routes/clean';
import { usersRouter } from './routes/users';
import { adminRouter } from './routes/admin';
import type { AuthContext } from './auth';

export interface Env {
  DB: D1Database;
  CACHER2_ROOT_API_KEY: string;
  CACHER2_R2_ACCOUNT_ID: string;
  CACHER2_R2_ACCESS_KEY_ID: string;
  CACHER2_R2_SECRET_ACCESS_KEY: string;
  CACHER2_WORLDS: string; // JSON: {"worldName": "bucketName"}
}

type Variables = { auth: AuthContext };

const app = new Hono<{ Bindings: Env; Variables: Variables }>();

app.route('/items', itemsRouter);
app.route('/push', pushRouter);
app.route('/pull', pullRouter);
app.route('/clean', cleanRouter);
app.route('/users', usersRouter);
app.route('/admin', adminRouter);

app.notFound(c => c.json({ error: 'not found' }, 404));
app.onError((err, c) => {
  console.error(err);
  return c.json({ error: 'internal server error' }, 500);
});

export default app;
