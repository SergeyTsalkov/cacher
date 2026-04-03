import { PutObjectCommand, S3Client } from '@aws-sdk/client-s3';
import { readFile, unlink } from 'fs/promises';
import { join } from 'path';
import { tmpdir } from 'os';
import { config } from './config';
import { db } from './db';
import { log } from './logger';

export async function runBackup(): Promise<void> {
  if (!config.backup_bucket) {
    log.debug('backup: no backup_bucket configured, skipping');
    return;
  }

  const timestamp = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
  const filename = `cacher-${timestamp}.db`;
  const tmpPath = join(tmpdir(), filename);

  try {
    log.debug(`backup: writing snapshot to ${tmpPath}`);
    db.exec(`VACUUM INTO '${tmpPath.replace(/'/g, "''")}'`);

    const data = await readFile(tmpPath);

    const client = new S3Client({
      region: 'auto',
      endpoint: `https://${config.r2.account_id}.r2.cloudflarestorage.com`,
      credentials: {
        accessKeyId: config.r2.access_key_id,
        secretAccessKey: config.r2.secret_access_key,
      },
    });

    await client.send(new PutObjectCommand({
      Bucket: config.backup_bucket,
      Key: filename,
      Body: data,
      ContentType: 'application/octet-stream',
    }));

    log.info(`backup: uploaded ${filename} (${data.length} bytes) to ${config.backup_bucket}`);
  } catch (err) {
    log.error(`backup: failed — ${err}`);
  } finally {
    await unlink(tmpPath).catch(() => {});
  }
}
