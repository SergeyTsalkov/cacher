import { DatabaseSync } from 'node:sqlite';
import { readFileSync } from 'fs';
import { fileURLToPath } from 'url';
import { dirname, join } from 'path';
import { config } from './config';

const __dirname = dirname(fileURLToPath(import.meta.url));

export const db = new DatabaseSync(config.db_path);
db.exec('PRAGMA journal_mode = WAL');

const schema = readFileSync(join(__dirname, '..', 'schema.sql'), 'utf8');
db.exec(schema);
