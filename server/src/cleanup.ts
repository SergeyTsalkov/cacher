import { versionCompare, sortVersionsDesc } from './version';
import { r2ConfigForWorld, itemKeyToObjectKey, objectKeyToItemKey, r2DeleteObject, r2ListObjects } from './r2';
import { db } from './db';
import { log } from './logger';

interface ItemRow {
  key: string;
  version: string;
  created_at: number;
}

export async function runCleanup(world: string): Promise<void> {
  const r2cfg = r2ConfigForWorld(world);
  const purgeAfter = 60 * 60 * 24; // 24 hours
  const now = Math.floor(Date.now() / 1000);

  log.info(`[cleanup] starting for world=${world}`);

  // Phase 1: Delete old settled versions from both DB and R2.
  // A "settled" version is the newest version available for ≥24h.
  // Any version older than the settled version is safe to delete.
  const allItems = db.prepare(
    `SELECT key, version, created_at FROM items WHERE world = ?`
  ).all(world) as ItemRow[];

  const byKey = new Map<string, ItemRow[]>();
  for (const row of allItems) {
    if (!byKey.has(row.key)) byKey.set(row.key, []);
    byKey.get(row.key)!.push(row);
  }
  for (const [key, versions] of byKey) {
    byKey.set(key, sortVersionsDesc(versions));
  }

  const toDelete: ItemRow[] = [];
  for (const [, versions] of byKey) {
    let settledVersion: string | null = null;
    for (const v of versions) {
      if (now - v.created_at >= purgeAfter) {
        settledVersion = v.version;
        break;
      }
    }
    if (!settledVersion) continue;
    for (const v of versions) {
      if (versionCompare(v.version, settledVersion) < 0) {
        toDelete.push(v);
      }
    }
  }

  for (const item of toDelete) {
    log.debug(`[cleanup] phase1: deleting old version ${item.key}@${item.version}`);
    await r2DeleteObject(r2cfg, itemKeyToObjectKey(item.key, item.version));
    db.prepare(`DELETE FROM items WHERE world = ? AND key = ? AND version = ?`)
      .run(world, item.key, item.version);
  }

  log.info(`[cleanup] phase1 done: deleted ${toDelete.length} old version(s)`);

  // Phase 2: Scan R2 for objects with no matching DB record (R2 orphans).
  let continuationToken: string | undefined = undefined;
  let page = 0;
  let totalOrphans = 0;

  while (true) {
    const { keys, nextToken } = await r2ListObjects(r2cfg, continuationToken);

    const validPairs = keys
      .map(k => ({ objectKey: k, parsed: objectKeyToItemKey(k) }))
      .filter((x): x is { objectKey: string; parsed: { key: string; version: string } } => x.parsed !== null);

    const orphanKeys: string[] = [];

    if (validPairs.length > 0) {
      const placeholders = validPairs.map(() => '(?, ?)').join(', ');
      const bindings = validPairs.flatMap(x => [x.parsed.key, x.parsed.version]);
      const existing = db.prepare(
        `SELECT key, version FROM items WHERE world = ? AND (key, version) IN (VALUES ${placeholders})`
      ).all(world, ...bindings) as { key: string; version: string }[];

      const existingSet = new Set(existing.map(r => `${r.key}@${r.version}`));
      for (const { objectKey, parsed } of validPairs) {
        if (!existingSet.has(`${parsed.key}@${parsed.version}`)) {
          orphanKeys.push(objectKey);
        }
      }

      for (const objectKey of orphanKeys) {
        log.debug(`[cleanup] phase2: deleting R2 orphan ${objectKey}`);
        await r2DeleteObject(r2cfg, objectKey);
      }
    }

    totalOrphans += orphanKeys.length;
    log.info(`[cleanup] phase2 page ${page}: scanned ${keys.length} object(s), deleted ${orphanKeys.length} orphan(s)`);

    if (!nextToken) break;
    continuationToken = nextToken;
    page++;
  }

  log.info(`[cleanup] done for world=${world}: phase2 deleted ${totalOrphans} R2 orphan(s)`);
}
