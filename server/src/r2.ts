import {
  S3Client,
  PutObjectCommand,
  GetObjectCommand,
  HeadObjectCommand,
  DeleteObjectCommand,
  ListObjectsV2Command,
} from '@aws-sdk/client-s3';
import { getSignedUrl } from '@aws-sdk/s3-request-presigner';
import { config } from './config';

export interface R2Config {
  client: S3Client;
  bucket: string;
}

export function itemKeyToObjectKey(itemKey: string, version: string): string {
  return `${itemKey.replace(/:/g, '/')}/${version}/_extract.tar.lz4`;
}

// Parses foo/bar/1700000000/_extract.tar.lz4 → { key: 'foo:bar', version: '1700000000' }
export function objectKeyToItemKey(objectKey: string): { key: string; version: string } | null {
  const m = objectKey.match(/^(.+)\/([^/]+)\/_extract\.tar\.lz4$/);
  if (!m) return null;
  return { key: m[1].replace(/\//g, ':'), version: m[2] };
}

export function r2ConfigForWorld(world: string): R2Config {
  const bucket = config.worlds[world];
  if (!bucket) throw new Error(`Unknown world: ${world}`);
  const client = new S3Client({
    region: 'auto',
    endpoint: `https://${config.r2.account_id}.r2.cloudflarestorage.com`,
    credentials: {
      accessKeyId: config.r2.access_key_id,
      secretAccessKey: config.r2.secret_access_key,
    },
  });
  return { client, bucket };
}

export async function presignedPutUrl(cfg: R2Config, objectKey: string, expiresInSeconds = 900): Promise<string> {
  return getSignedUrl(cfg.client, new PutObjectCommand({ Bucket: cfg.bucket, Key: objectKey }), { expiresIn: expiresInSeconds });
}

export async function presignedGetUrl(cfg: R2Config, objectKey: string, expiresInSeconds = 3600): Promise<string> {
  return getSignedUrl(cfg.client, new GetObjectCommand({ Bucket: cfg.bucket, Key: objectKey }), { expiresIn: expiresInSeconds });
}

export async function r2HeadObject(cfg: R2Config, objectKey: string): Promise<boolean> {
  try {
    await cfg.client.send(new HeadObjectCommand({ Bucket: cfg.bucket, Key: objectKey }));
    return true;
  } catch {
    return false;
  }
}

export async function r2DeleteObject(cfg: R2Config, objectKey: string): Promise<void> {
  await cfg.client.send(new DeleteObjectCommand({ Bucket: cfg.bucket, Key: objectKey }));
}

export async function r2ListObjects(cfg: R2Config, continuationToken?: string): Promise<{ keys: string[]; nextToken?: string }> {
  const resp = await cfg.client.send(new ListObjectsV2Command({
    Bucket: cfg.bucket,
    MaxKeys: 200,
    ContinuationToken: continuationToken,
  }));
  const keys = (resp.Contents ?? []).map(obj => obj.Key!).filter(Boolean);
  return { keys, nextToken: resp.NextContinuationToken };
}
