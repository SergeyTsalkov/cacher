#!/usr/bin/env php
<?php
// TEMPORARY — delete after migration to D1 is complete

namespace Cacher2;
require_once $_composer_autoload_path ?? __DIR__ . '/../vendor/autoload.php';

$opts = getopt('', ['world:', 'dry-run', 'batch-size:']);
$dryRun    = isset($opts['dry-run']);
$batchSize = (int)($opts['batch-size'] ?? 500);
$targetWorld = $opts['world'] ?? null;

$devFile = __DIR__ . '/../.dev';
if (file_exists($devFile)) require $devFile;

// Validate required constants
foreach (['CACHER_DB_DSN', 'CACHER_DB_USER', 'CACHER_DB_PASS', 'CACHER2_API_URL', 'CACHER2_API_KEY'] as $c) {
  if (!defined($c)) {
    echo "Error: constant $c is not defined\n";
    exit(1);
  }
}

$db = new \MeekroDB(
  constant('CACHER_DB_DSN'),
  constant('CACHER_DB_USER'),
  constant('CACHER_DB_PASS')
);

$rows = $db->query("SELECT `key`, `version`, UNIX_TIMESTAMP(created_at) AS created_at FROM items ORDER BY created_at");
echo sprintf("Found %d items in MySQL.\n", count($rows));

if ($dryRun) {
  echo "Dry run — exiting without uploading.\n";
  exit(0);
}

$api = new RemoteApiClient(
  constant('CACHER2_API_URL'),
  constant('CACHER2_API_KEY')
);

// Resolve world
if (!$targetWorld) {
  $worldsInfo = $api->adminWorlds();
  $targetWorld = $worldsInfo['default'];
  echo "Using default world: $targetWorld\n";
} else {
  echo "Using world: $targetWorld\n";
}

$chunks = array_chunk($rows, $batchSize);
$totalInserted = 0;

foreach ($chunks as $i => $chunk) {
  $items = array_map(fn($r) => [
    'key'        => $r['key'],
    'version'    => $r['version'],
    'created_at' => (int)$r['created_at'],
  ], $chunk);

  $result = $api->adminMigrate($targetWorld, $items);
  $totalInserted += $result['inserted'];
  echo sprintf("Batch %d/%d: inserted %d, skipped %d\n",
    $i + 1, count($chunks), $result['inserted'], $result['skipped']);
}

echo sprintf("Migration complete. %d items migrated to world '%s'.\n", $totalInserted, $targetWorld);
