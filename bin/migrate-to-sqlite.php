#!/usr/bin/env php
<?php
// Migrates cacher2 items and users from MySQL directly into the new SQLite database.
// Bypasses the HTTP API entirely — run this before starting the new server.
// Safe to run multiple times — uses INSERT OR IGNORE throughout.
//
// Usage:
//   php bin/migrate-to-sqlite.php --sqlite=PATH --world=NAME [--dry-run]
//
// Options:
//   --sqlite=PATH   Path to the SQLite database file (db_path in config.toml)
//   --world=NAME    World to assign all migrated items to
//   --dry-run       Show what would be migrated without writing anything
//
// MySQL connection is read from .dev (CACHER_DB_DSN, CACHER_DB_USER, CACHER_DB_PASS).

namespace Cacher2;
require_once $_composer_autoload_path ?? __DIR__ . '/../vendor/autoload.php';

$opts = getopt('', ['sqlite:', 'world:', 'dry-run']);
$dryRun = isset($opts['dry-run']);

foreach (['CACHER_DB_DSN', 'CACHER_DB_USER', 'CACHER_DB_PASS'] as $c) {
  if (!defined($c)) {
    echo "Error: $c is not defined — set it in .dev\n";
    exit(1);
  }
}
if (empty($opts['sqlite'])) {
  echo "Error: --sqlite=PATH is required\n";
  exit(1);
}
if (empty($opts['world'])) {
  echo "Error: --world=NAME is required\n";
  exit(1);
}

$sqlitePath = $opts['sqlite'];
$world = $opts['world'];

// --- Read from MySQL ---

$mysql = new \MeekroDB(
  constant('CACHER_DB_DSN'),
  constant('CACHER_DB_USER'),
  constant('CACHER_DB_PASS')
);

$items = $mysql->query(
  "SELECT `key`, version, UNIX_TIMESTAMP(created_at) AS created_at FROM items ORDER BY created_at"
);
echo sprintf("Found %d item(s) in MySQL.\n", count($items));

$users = [];
try {
  $users = $mysql->query(
    "SELECT name, api_key, level, world, created_by, UNIX_TIMESTAMP(created_at) AS created_at FROM users"
  );
  echo sprintf("Found %d user(s) in MySQL.\n", count($users));
} catch (\Exception $e) {
  echo "Note: could not read users from MySQL ({$e->getMessage()}) — skipping.\n";
}

if ($dryRun) {
  echo "Dry run — exiting without writing.\n";
  exit(0);
}

// --- Write to SQLite ---

$sqlite = new \PDO("sqlite:$sqlitePath");
$sqlite->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$sqlite->exec('PRAGMA journal_mode = WAL');

// Apply schema (all statements are CREATE ... IF NOT EXISTS, safe to re-run)
$schema = file_get_contents(__DIR__ . '/../c2server/internal/schema.sql');
$sqlite->exec($schema);

// Items
$insertItem = $sqlite->prepare(
  "INSERT OR IGNORE INTO items (world, key, version, created_at) VALUES (?, ?, ?, ?)"
);

$itemsInserted = 0;
$itemsSkipped  = 0;

foreach ($items as $row) {
  $insertItem->execute([$world, $row['key'], $row['version'], (int)$row['created_at']]);
  if ($insertItem->rowCount() > 0) $itemsInserted++;
  else $itemsSkipped++;
}

echo sprintf("Items:  inserted %d, skipped %d (already present).\n", $itemsInserted, $itemsSkipped);

// Users
if ($users) {
  $insertUser = $sqlite->prepare(
    "INSERT OR IGNORE INTO users (name, api_key, level, world, created_by, created_at)
     VALUES (?, ?, ?, ?, ?, ?)"
  );

  $usersInserted = 0;
  $usersSkipped  = 0;

  foreach ($users as $row) {
    $insertUser->execute([
      $row['name'],
      $row['api_key'],
      (int)$row['level'],
      $row['world']      ?? $world,
      $row['created_by'] ?? 'migration',
      (int)$row['created_at'],
    ]);
    if ($insertUser->rowCount() > 0) $usersInserted++;
    else $usersSkipped++;
  }

  echo sprintf("Users:  inserted %d, skipped %d (already present).\n", $usersInserted, $usersSkipped);
}

echo sprintf("Done. Items migrated into world '%s'.\n", $world);
