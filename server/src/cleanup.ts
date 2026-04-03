import { WorkflowEntrypoint, WorkflowStep, WorkflowEvent } from 'cloudflare:workers';
import { versionCompare, sortVersionsDesc } from './version';
import { r2ConfigForWorld, itemKeyToObjectKey, objectKeyToItemKey, r2DeleteObject, r2ListObjects } from './r2';
import { getWorlds } from './auth';
import type { Env } from './index';

interface CleanupParams {
  world: string;
}

interface ItemRow {
  key: string;
  version: string;
  created_at: number;
}

export class CleanupWorkflow extends WorkflowEntrypoint<Env, CleanupParams> {
  async run(event: WorkflowEvent<CleanupParams>, step: WorkflowStep) {
    const { world } = event.payload;
    const r2cfg = r2ConfigForWorld(this.env, world);
    const purgeAfter = 60 * 60 * 24; // 24 hours
    const now = Math.floor(Date.now() / 1000);

    console.log(`[cleanup] starting for world=${world}`);

    // Phase 1: Delete old settled versions from both D1 and R2.
    // A "settled" version is the newest version that has been available for ≥24h.
    // Any version older than the settled version is safe to delete.
    const phase1 = await step.do(`phase1-${world}`, async () => {
      const { results } = await this.env.DB.prepare(
        `SELECT key, version, created_at FROM items WHERE world = ?`
      ).bind(world).all<ItemRow>();

      const byKey = new Map<string, ItemRow[]>();
      for (const row of results) {
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
        const objectKey = itemKeyToObjectKey(item.key, item.version);
        console.log(`[cleanup] phase1: deleting old version ${item.key}@${item.version}`);
        await r2DeleteObject(r2cfg, objectKey);
        await this.env.DB.prepare(
          `DELETE FROM items WHERE world = ? AND key = ? AND version = ?`
        ).bind(world, item.key, item.version).run();
      }

      return { deleted: toDelete.length };
    });

    console.log(`[cleanup] phase1 done: deleted ${phase1.deleted} old version(s)`);

    // Phase 2: Scan R2 for objects with no matching D1 record (R2 orphans).
    // Uses 200-object pages to stay within D1's parameter binding limit.
    let continuationToken: string | undefined = undefined;
    let page = 0;
    let totalOrphans = 0;

    while (true) {
      const token = continuationToken; // capture before step for correct replay semantics
      const result = await step.do(`r2-scan-page-${page}-${world}`, async () => {
        const { keys, nextToken } = await r2ListObjects(r2cfg, token);

        const validPairs = keys
          .map(k => ({ objectKey: k, parsed: objectKeyToItemKey(k) }))
          .filter((x): x is { objectKey: string; parsed: { key: string; version: string } } => x.parsed !== null);

        const orphanKeys: string[] = [];

        if (validPairs.length > 0) {
          const placeholders = validPairs.map(() => '(?, ?)').join(', ');
          const bindings = validPairs.flatMap(x => [x.parsed.key, x.parsed.version]);
          const { results: existing } = await this.env.DB.prepare(
            `SELECT key, version FROM items WHERE world = ? AND (key, version) IN (VALUES ${placeholders})`
          ).bind(world, ...bindings).all<{ key: string; version: string }>();

          const existingSet = new Set(existing.map(r => `${r.key}@${r.version}`));
          for (const { objectKey, parsed } of validPairs) {
            if (!existingSet.has(`${parsed.key}@${parsed.version}`)) {
              orphanKeys.push(objectKey);
            }
          }

          for (const objectKey of orphanKeys) {
            console.log(`[cleanup] phase2: deleting R2 orphan ${objectKey}`);
            await r2DeleteObject(r2cfg, objectKey);
          }
        }

        return { scanned: keys.length, orphansDeleted: orphanKeys.length, nextToken };
      });

      totalOrphans += result.orphansDeleted;
      console.log(`[cleanup] phase2 page ${page}: scanned ${result.scanned} object(s), deleted ${result.orphansDeleted} orphan(s)`);

      if (!result.nextToken) break;
      continuationToken = result.nextToken;
      page++;
    }

    console.log(`[cleanup] done for world=${world}: phase2 deleted ${totalOrphans} R2 orphan(s)`);
  }
}
