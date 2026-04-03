import { config } from './config';

export const log = {
  debug: (msg: string, ...args: unknown[]) => {
    if (config.debug) console.log(`[debug] ${msg}`, ...args);
  },
  info:  (msg: string, ...args: unknown[]) => console.log(`[info] ${msg}`, ...args),
  warn:  (msg: string, ...args: unknown[]) => console.warn(`[warn] ${msg}`, ...args),
  error: (msg: string, ...args: unknown[]) => console.error(`[error] ${msg}`, ...args),
};
