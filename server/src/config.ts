import { readFileSync } from 'fs';
import { parse } from 'smol-toml';
import { resolve } from 'path';

interface ConfigFile {
  port?: number;
  db_path?: string;
  root_api_key: string;
  backup_bucket?: string;
  tls?: {
    cert: string;
    key: string;
  };
  r2: {
    account_id: string;
    access_key_id: string;
    secret_access_key: string;
  };
  worlds: Record<string, string>;
}

function loadConfig() {
  const configArg = process.argv.find(a => a.startsWith('--config='));
  const configPath = configArg
    ? resolve(configArg.split('=')[1])
    : resolve(process.cwd(), 'config.toml');

  let raw: string;
  try {
    raw = readFileSync(configPath, 'utf8');
  } catch {
    console.error(`Error: cannot read config file: ${configPath}`);
    process.exit(1);
  }

  let data: ConfigFile;
  try {
    data = parse(raw) as ConfigFile;
  } catch (e) {
    console.error(`Error: failed to parse config file: ${e}`);
    process.exit(1);
  }

  if (!data.root_api_key) {
    console.error('Error: config is missing root_api_key');
    process.exit(1);
  }
  if (!data.r2?.account_id || !data.r2?.access_key_id || !data.r2?.secret_access_key) {
    console.error('Error: config is missing required [r2] credentials');
    process.exit(1);
  }
  if (!data.worlds || Object.keys(data.worlds).length === 0) {
    console.error('Error: config [worlds] must define at least one world');
    process.exit(1);
  }

  return {
    port:          data.port    ?? 3000,
    db_path:       data.db_path ?? './cacher.db',
    root_api_key:  data.root_api_key,
    backup_bucket: data.backup_bucket,
    tls:           data.tls,
    r2:            data.r2,
    worlds:        data.worlds,
    debug:         process.argv.includes('--debug'),
  };
}

export const config = loadConfig();
