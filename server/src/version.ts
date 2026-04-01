// Version comparison that mirrors PHP's version_compare():
// splits on [._+-], compares numeric segments numerically so
// e.g. 1.2.129 > 1.2.5 and 1700000002 > 1700000001.
export function versionCompare(a: string, b: string): number {
  const parts = (v: string): (number | string)[] =>
    v.split(/[.\-_+]/).map(s => {
      const n = parseInt(s, 10);
      return isNaN(n) ? s : n;
    });

  const pa = parts(a);
  const pb = parts(b);
  const len = Math.max(pa.length, pb.length);

  for (let i = 0; i < len; i++) {
    const x = pa[i] ?? 0;
    const y = pb[i] ?? 0;
    if (typeof x === 'number' && typeof y === 'number') {
      if (x !== y) return x - y;
    } else {
      const sx = String(x), sy = String(y);
      if (sx < sy) return -1;
      if (sx > sy) return 1;
    }
  }
  return 0;
}

export interface VersionRow {
  version: string;
  created_at: number;
}

// Pick the row with the highest version from an array.
export function latestVersion<T extends VersionRow>(rows: T[]): T | null {
  if (rows.length === 0) return null;
  return rows.reduce((best, row) =>
    versionCompare(row.version, best.version) > 0 ? row : best
  );
}

// Sort rows newest-first by version.
export function sortVersionsDesc<T extends VersionRow>(rows: T[]): T[] {
  return [...rows].sort((a, b) => versionCompare(b.version, a.version));
}
