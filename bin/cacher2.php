#!/usr/bin/env php
<?php
namespace Cacher2;
require_once $_composer_autoload_path ?? __DIR__ . '/../vendor/autoload.php';

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

  $Cacher = new Cacher($username);

  foreach ($keys as $key) {
    $Cacher->install($key, $path, $Cmd->flag('symlink'));
  }
}

function copy(ParsedCommandLine $Cmd) {
  list($username, $path) = $Cmd->args(1, 2);
  $keys = $Cmd->args(3);
  require_args($username, $path, $keys[0]);

  $Cacher = new Cacher($username);

  foreach ($keys as $key) {
    $Cacher->copy($key, $path);
  }
}

function uninstall(ParsedCommandLine $Cmd) {
  $username = $Cmd->arg(1);
  $keys = $Cmd->args(2);
  require_args($username, $keys[0]);

  $Cacher = new Cacher($username);
  foreach ($keys as $key) {
    $Cacher->uninstall($key);
  }
}

function upgrade(ParsedCommandLine $Cmd) {
  $username = $Cmd->arg(1);
  require_args($username);

  $Cacher = new Cacher($username);
  $Cacher->upgrade();
}

function deletelocal(ParsedCommandLine $Cmd) {
  list($item, $version) = $Cmd->args(1, 2);
  require_args($item);

  $Cacher = new Cacher();
  $Cacher->deletelocal($item, $version);
}

function deleteremote(ParsedCommandLine $Cmd) {
  list($item, $version) = $Cmd->args(1, 2);
  require_args($item);

  $Cacher = new Cacher();
  $Cacher->deleteremote($item, $version);
}

function push(ParsedCommandLine $Cmd) {
  list($path, $key, $version) = $Cmd->args(1, 3);
  require_args($path, $key);

  $Cacher = new Cacher();
  $Cacher->push($path, $key, $version);
}

function pull(ParsedCommandLine $Cmd) {
  $keys = $Cmd->args(1);
  require_args($keys[0]);
  
  $Cacher = new Cacher();
  foreach ($keys as $key) {
    $Cacher->pull($key);
  }
}

function local(ParsedCommandLine $Cmd) {
  $Cacher = new Cacher();
  $results = $Cacher->localinfo($Cmd->arg(1), $Cmd->flag('exact'));

  if ($Cmd->flag('json')) {
    echo json_encode($results, JSON_PRETTY_PRINT) . "\n";
  } else {
    foreach ($results as $key => $item) {
      $status = $item['up_to_date'] ? 'up-to-date' : 'needs update';
      echo sprintf("%s (%s, %s)\n", $key, $item['local_version'], $status);
    }
  }
}

function remote(ParsedCommandLine $Cmd) {
  $Cacher = new Cacher();
  $results = $Cacher->remoteinfo($Cmd->arg(1), $Cmd->flag('exact'));

  if ($Cmd->flag('json')) {
    echo json_encode($results, JSON_PRETTY_PRINT) . "\n";
  } else {
    foreach ($results as $key => $item) {
      echo sprintf("%s (%s)\n", $key, $item['version']);
    }
  }
}

function installed(ParsedCommandLine $Cmd) {
  $username = $Cmd->arg(1);
  require_args($username);

  $Cacher = new Cacher($username);
  $results = $Cacher->installedinfo();

  if ($Cmd->flag('json')) {
    echo json_encode($results, JSON_PRETTY_PRINT) . "\n";
  } else {
    foreach ($results as $key => $item) {
      $status = $item['up_to_date'] ? 'up-to-date' : 'needs update';

      $props = [$item['installed_version'], $status];
      if ($item['is_symlink']) {
        $props[] = 'symlink';
      }

      echo sprintf("%s (%s): %s\n", $key, implode(', ', $props), $item['path']);
    }
  }
}

function cleanlocal(ParsedCommandLine $Cmd) {
  $Cacher = new Cacher();
  $Cacher->cleanlocal();
}

function cleanremote(ParsedCommandLine $Cmd) {
  $Cacher = new Cacher();
  $Cacher->cleanremote();
}

function help() {
  echo "Usage: cacher2 <command> [options]\n";
  echo "Commands:\n";
  echo "  push <path> <key> [version] -- push new item to remote cache\n";
  echo "  pull <key1> [key2] ... -- pull item from remote to local cache\n";
  echo "  local [search] [--json] [--exact] -- list local cache items\n";
  echo "  remote [search] [--json] [--exact] -- list remote cache items\n\n";

  echo "  copy <username> <path> <key1> [key2] ... -- copy item from local cache (like install, but won't be upgraded)\n";
  echo "  install [--symlink] <username> <path> <key1> [key2] ... -- install item from local cache\n";
  echo "  uninstall <username> <key1> [key2] ... -- uninstall item from local cache\n";
  echo "  upgrade <username> -- upgrade all installed items\n";
  echo "  installed [--json] <username> -- list installed items\n\n";

  echo "  cleanlocal -- delete old local items\n";
  echo "  cleanremote -- delete old remote items\n";
  echo "  deletelocal <key> [version] - delete item from local cache\n";
  echo "  deleteremote <key> [version] - delete item from remote cache\n";
  die();
}

function require_args(...$args) {
  foreach ($args as $arg) {
    if (!$arg) {
      help();
    }
  }
}
