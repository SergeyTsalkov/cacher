import type { Env } from './index';

export interface R2Config {
  accountId: string;
  bucket: string;
  accessKeyId: string;
  secretAccessKey: string;
}

export function itemKeyToObjectKey(itemKey: string, version: string): string {
  return `${itemKey.replace(/:/g, '/')}/${version}/_extract.tar.lz4`;
}

export function r2ConfigForWorld(env: Env, world: string): R2Config {
  const worlds = JSON.parse(env.CACHER2_WORLDS) as Record<string, string>;
  const bucket = worlds[world];
  if (!bucket) throw new Error(`Unknown world: ${world}`);
  return {
    accountId: env.CACHER2_R2_ACCOUNT_ID,
    bucket,
    accessKeyId: env.CACHER2_R2_ACCESS_KEY_ID,
    secretAccessKey: env.CACHER2_R2_SECRET_ACCESS_KEY,
  };
}

export async function presignedPutUrl(cfg: R2Config, objectKey: string, expiresInSeconds = 900): Promise<string> {
  return buildPresignedUrl(cfg, 'PUT', objectKey, expiresInSeconds);
}

export async function presignedGetUrl(cfg: R2Config, objectKey: string, expiresInSeconds = 3600): Promise<string> {
  return buildPresignedUrl(cfg, 'GET', objectKey, expiresInSeconds);
}

export async function r2HeadObject(cfg: R2Config, objectKey: string): Promise<boolean> {
  const url = await buildPresignedUrl(cfg, 'HEAD', objectKey, 60);
  const resp = await fetch(url, { method: 'HEAD' });
  return resp.status === 200;
}

export async function r2DeleteObject(cfg: R2Config, objectKey: string): Promise<void> {
  const url = await buildPresignedUrl(cfg, 'DELETE', objectKey, 60);
  await fetch(url, { method: 'DELETE' });
}

async function buildPresignedUrl(
  cfg: R2Config,
  method: 'GET' | 'PUT' | 'DELETE' | 'HEAD',
  objectKey: string,
  expiresInSeconds: number
): Promise<string> {
  const host = `${cfg.accountId}.r2.cloudflarestorage.com`;
  const region = 'auto';
  const service = 's3';
  const now = new Date();
  const dateStamp = now.toISOString().slice(0, 10).replace(/-/g, '');
  const amzDate = now.toISOString().replace(/[:\-]/g, '').slice(0, 15) + 'Z';

  const credentialScope = `${dateStamp}/${region}/${service}/aws4_request`;
  const credential = `${cfg.accessKeyId}/${credentialScope}`;

  const params = new URLSearchParams({
    'X-Amz-Algorithm': 'AWS4-HMAC-SHA256',
    'X-Amz-Credential': credential,
    'X-Amz-Date': amzDate,
    'X-Amz-Expires': String(expiresInSeconds),
    'X-Amz-SignedHeaders': 'host',
  });

  const canonicalUri = `/${cfg.bucket}/${objectKey}`;
  const canonicalQueryString = [...params.entries()]
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`)
    .join('&');
  const canonicalHeaders = `host:${host}\n`;
  const signedHeaders = 'host';

  const canonicalRequest = [
    method,
    canonicalUri,
    canonicalQueryString,
    canonicalHeaders,
    signedHeaders,
    'UNSIGNED-PAYLOAD',
  ].join('\n');

  const hashedCanonicalRequest = await sha256hex(canonicalRequest);

  const stringToSign = [
    'AWS4-HMAC-SHA256',
    amzDate,
    credentialScope,
    hashedCanonicalRequest,
  ].join('\n');

  const signingKey = await deriveSigningKey(cfg.secretAccessKey, dateStamp, region, service);
  const signature = await hmacHex(signingKey, stringToSign);

  params.append('X-Amz-Signature', signature);

  // Sort params for canonical query string — rebuild after signature
  const finalParams = new URLSearchParams([
    ...[...params.entries()].sort(([a], [b]) => a.localeCompare(b)),
  ]);

  return `https://${host}${canonicalUri}?${finalParams.toString()}`;
}

async function sha256hex(message: string): Promise<string> {
  const data = new TextEncoder().encode(message);
  const hash = await crypto.subtle.digest('SHA-256', data);
  return hexEncode(new Uint8Array(hash));
}

async function hmacHex(key: ArrayBuffer, message: string): Promise<string> {
  const cryptoKey = await crypto.subtle.importKey(
    'raw', key, { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']
  );
  const sig = await crypto.subtle.sign('HMAC', cryptoKey, new TextEncoder().encode(message));
  return hexEncode(new Uint8Array(sig));
}

async function hmacBytes(key: ArrayBuffer | string, message: string): Promise<ArrayBuffer> {
  const rawKey = typeof key === 'string' ? new TextEncoder().encode(key) : key;
  const cryptoKey = await crypto.subtle.importKey(
    'raw', rawKey, { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']
  );
  return crypto.subtle.sign('HMAC', cryptoKey, new TextEncoder().encode(message));
}

async function deriveSigningKey(secretKey: string, dateStamp: string, region: string, service: string): Promise<ArrayBuffer> {
  const kDate = await hmacBytes('AWS4' + secretKey, dateStamp);
  const kRegion = await hmacBytes(kDate, region);
  const kService = await hmacBytes(kRegion, service);
  return hmacBytes(kService, 'aws4_request');
}

function hexEncode(bytes: Uint8Array): string {
  return Array.from(bytes).map(b => b.toString(16).padStart(2, '0')).join('');
}
