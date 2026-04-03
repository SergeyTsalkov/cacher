import { config } from './config';
import { log } from './logger';
import { runCleanup } from './cleanup';
import { runBackup } from './backup';

async function runScheduled(): Promise<void> {
  log.info('scheduler: starting hourly cleanup + backup');

  for (const world of Object.keys(config.worlds)) {
    try {
      await runCleanup(world);
    } catch (err) {
      log.error(`scheduler: cleanup failed for world=${world} — ${err}`);
    }
  }

  try {
    await runBackup();
  } catch (err) {
    log.error(`scheduler: backup failed — ${err}`);
  }

  log.info('scheduler: done');
}

export function startScheduler(): void {
  const HOUR = 60 * 60 * 1000;
  // Run 5 seconds after startup, then every hour.
  setTimeout(() => { runScheduled().catch(err => log.error(`scheduler: ${err}`)); }, 5_000);
  setInterval(() => { runScheduled().catch(err => log.error(`scheduler: ${err}`)); }, HOUR);
  log.info('scheduler: started (runs hourly)');
}
