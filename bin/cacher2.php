#!/usr/bin/env php
<?php
namespace Cacher2;
require_once __DIR__ . '/../vendor/autoload.php';

$Cmd = new \ParsedCommandLine($argv);
$cmd = $Cmd->arg(0);
$Cacher = new \Cacher();

if ($cmd == 'push') {
  list($path, $key, $version) = $Cmd->args(1, 3);
  require_args($path, $key);

  $Cacher->push($path, $key, $version);
}
else if ($cmd == 'pull') {
  list($key, $version) = $Cmd->args(1, 2);
  require_args($key);

  $Cacher->pull($key);
}
else if ($cmd == 'info') {
  $local = $Cacher->localInfo();
  $remote = $Cacher->remoteInfo();
  foreach ($local as $key => $version) {
    $remote_version = $remote[$key] ?? null;
    if (is_null($remote_version)) $status = 'missing from remote';
    else if ($remote_version == $version) $status = 'up-to-date';
    else $status = 'needs update';

    echo sprintf("%s (%s, %s)\n", $key, $version, $status);
  }
}
else {
  help();
}

function help() {
  echo "Usage: cacher2 <command> [options]\n";
  echo "Commands:\n";
  echo "  push <path> <key> [version]\n";
  echo "  pull <key>\n";
  die();
}

function require_args(...$args) {
  foreach ($args as $arg) {
    if (!$arg) {
      help();
    }
  }
}
