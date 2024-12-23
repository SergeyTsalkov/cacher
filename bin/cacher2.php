#!/usr/bin/env php
<?php
namespace Cacher2;
require_once __DIR__ . '/../vendor/autoload.php';

$Cmd = new ParsedCommandLine($argv);
$cmd = $Cmd->arg(0);
$cmd = preg_replace('/[^a-z]/', '', strval($cmd));
$func = "Cacher2\\$cmd";

if ($cmd && is_callable($func)) {
  $func($Cmd);
} else {
  help();
}

function install(ParsedCommandLine $Cmd) {
  list($username, $path) = $Cmd->args(1, 2);
  $keys = $Cmd->args(3);
  require_args($username, $path, $keys[0]);

  $Cacher = new \Cacher($username);

  foreach ($keys as $key) {
    $Cacher->install($key, $path);
  }
}

function copy(ParsedCommandLine $Cmd) {
  list($username, $path) = $Cmd->args(1, 2);
  $keys = $Cmd->args(3);
  require_args($username, $path, $keys[0]);

  $Cacher = new \Cacher($username);

  foreach ($keys as $key) {
    $Cacher->copy($key, $path);
  }
}

function uninstall(ParsedCommandLine $Cmd) {
  $username = $Cmd->arg(1);
  $keys = $Cmd->args(2);
  require_args($username, $keys[0]);

  $Cacher = new \Cacher($username);
  foreach ($keys as $key) {
    $Cacher->uninstall($key);
  }
}

function upgrade(ParsedCommandLine $Cmd) {
  $username = $Cmd->arg(1);
  require_args($username);

  $Cacher = new \Cacher($username);
  $Cacher->upgrade();
}

function deletelocal(ParsedCommandLine $Cmd) {
  $items = $Cmd->args(1);
  require_args($items[0]);

  $Cacher = new \Cacher();
  foreach ($items as $item) {
    $Cacher->deletelocal($item);
  }
}

function push(ParsedCommandLine $Cmd) {
  list($path, $key, $version) = $Cmd->args(1, 3);
  require_args($path, $key);

  $Cacher = new \Cacher();
  $Cacher->push($path, $key, $version);
}

function pull(ParsedCommandLine $Cmd) {
  $keys = $Cmd->args(1);
  require_args($keys[0]);
  
  $Cacher = new \Cacher();
  foreach ($keys as $key) {
    $Cacher->pull($key);
  }
}

function _local(string $match=null): array {
  $Cacher = new \Cacher();
  $local = $Cacher->localInfo($match);
  $remote = $Cacher->remoteInfo($match);
  $results = [];
  foreach ($local as $key => $local_item) {
    $remote_item = $remote[$key] ?? null;

    $results[$key] = [
      'local_version' => $local_item['version'],
      'remote_version' => $remote_item['version'] ?? null,
    ];
  }
  return $results;
}

function _remote(string $match=null): array {
  $Cacher = new \Cacher();
  $remote = $Cacher->remoteInfo($match);
  $results = [];
  foreach ($remote as $key => $remote_item) {
    $results[$key] = [
      'version' => $remote_item['version'],
    ];
  }
  return $results;
}

function _installed(string $username): array {
  $Cacher = new \Cacher($username);
  $installed = $Cacher->installed();

  $results = [];
  foreach ($installed as $key => $item) {
    $results[$key] = [
      'version' => $item['version'],
      'path' => $item['path'],
    ];
  }
  return $results;
}

function local(ParsedCommandLine $Cmd) {
  $results = _local($Cmd->arg(1));
  if ($Cmd->flag('json')) {
    echo json_encode($results, JSON_PRETTY_PRINT) . "\n";
  } else {
    foreach ($results as $key => $item) {
      if (is_null($item['remote_version'])) {
        $status = 'missing from remote';
      } else if ($item['local_version'] == $item['remote_version']) {
        $status = 'up-to-date';
      } else {
        $status = 'needs update';
      }

      echo sprintf("%s (%s, %s)\n", $key, $item['local_version'], $status);
    }
  }
}

function remote(ParsedCommandLine $Cmd) {
  $results = _remote($Cmd->arg(1));
  if ($Cmd->flag('json')) {
    echo json_encode($results, JSON_PRETTY_PRINT) . "\n";
  } else {
    foreach ($results as $key => $item) {
      echo sprintf("%s (%s)\n", $key, $item['version']);
    }
  }
}

function cleanlocal(ParsedCommandLine $Cmd) {
  $Cacher = new \Cacher();
  $Cacher->cleanlocal();
}

function cleanremote(ParsedCommandLine $Cmd) {
  $Cacher = new \Cacher();
  $Cacher->cleanremote();
}

function installed(ParsedCommandLine $Cmd) {
  $username = $Cmd->arg(1);
  require_args($username);

  $installed = _installed($username);

  if ($Cmd->flag('json')) {
    echo json_encode($installed, JSON_PRETTY_PRINT) . "\n";
  } else {
    foreach ($installed as $key => $item) {
      echo sprintf("%s (%s): %s\n", $key, $item['version'], $item['path']);
    }
  }

  
}

function help() {
  echo "Usage: cacher2 <command> [options]\n";
  echo "Commands:\n";
  echo "  push <path> <key> [version] -- push new item to remote cache\n";
  echo "  pull <key1> [key2] ... -- pull item from remote to local cache\n";
  echo "  local [--json] -- list local cache items\n";
  echo "  remote [--json] -- list remote cache items\n\n";

  echo "  copy <username> <path> <key1> [key2] ... -- copy item from local cache (like install, but won't be upgraded)\n";
  echo "  install <username> <path> <key1> [key2] ... -- install item from local cache\n";
  echo "  uninstall <username> <key1> [key2] ... -- uninstall item from local cache\n";
  echo "  upgrade <username> -- upgrade all installed items\n";
  echo "  installed [--json] <username> -- list installed items\n\n";

  echo "  cleanlocal -- delete old local items\n";
  echo "  cleanremote -- delete old remote items\n";
  echo "  deletelocal <key1> [key2] ... - delete items from local cache\n";
  die();
}

function require_args(...$args) {
  foreach ($args as $arg) {
    if (!$arg) {
      help();
    }
  }
}
