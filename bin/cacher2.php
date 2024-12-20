#!/usr/bin/env php
<?php
namespace Cacher2;
require_once __DIR__ . '/../vendor/autoload.php';

$Cmd = new ParsedCommandLine($argv);
$cmd = $Cmd->arg(0);

$valid_commands = ['push', 'pull', 'local', 'remote', 'installed', 'install', 'uninstall', 'cleanlocal'];
if (in_array($cmd, $valid_commands)) {
  $func = "Cacher2\\$cmd";
  $func($Cmd);
} else {
  help();
}

function install(ParsedCommandLine $Cmd) {
  list($key, $path) = $Cmd->args(1, 2);
  require_args($key, $path);

  $Cacher = new \Cacher();
  $Cacher->install($key, $path);
}

function uninstall(ParsedCommandLine $Cmd) {
  list($key) = $Cmd->args(1);
  require_args($key);

  $Cacher = new \Cacher();
  $Cacher->uninstall($key);
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

function installed(ParsedCommandLine $Cmd) {
  $Cacher = new \Cacher();
  $installed = $Cacher->installed();
  foreach ($installed as $key => $item) {
    echo sprintf("%s (%s): %s\n", $key, $item['version'], $item['path']);
  }
}

function help() {
  echo "Usage: cacher2 <command> [options]\n";
  echo "Commands:\n";
  echo "  push <path> <key> [version]\n";
  echo "  pull <key>\n";
  echo "  local\n";
  echo "  remote\n";
  echo "  installed\n";
  echo "  install <key> <path>\n";
  echo "  uninstall <key>\n";
  echo "  cleanlocal\n";
  die();
}

function require_args(...$args) {
  foreach ($args as $arg) {
    if (!$arg) {
      help();
    }
  }
}
