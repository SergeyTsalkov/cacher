#!/usr/bin/env php
<?php
namespace Cacher2;
require_once __DIR__ . '/../vendor/autoload.php';

$Cmd = new ParsedCommandLine($argv);
$cmd = $Cmd->arg(0);

$valid_commands = ['push', 'pull', 'info', 'install', 'uninstall', 'installed'];
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
  list($key, $version) = $Cmd->args(1, 2);
  require_args($key);
  
  $Cacher = new \Cacher();
  $Cacher->pull($key);
}

function info(ParsedCommandLine $Cmd) {
  $Cacher = new \Cacher();
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

function installed(ParsedCommandLine $Cmd) {
  $Cacher = new \Cacher();
  $installed = $Cacher->installed();
  foreach ($installed as $key => $version) {
    echo sprintf("%s (%s)\n", $key, $version);
  }
}

function help() {
  echo "Usage: cacher2 <command> [options]\n";
  echo "Commands:\n";
  echo "  push <path> <key> [version]\n";
  echo "  pull <key>\n";
  echo "  install <key> <path>\n";
  echo "  uninstall <key>\n";
  echo "  installed\n";
  echo "  info\n";
  die();
}

function require_args(...$args) {
  foreach ($args as $arg) {
    if (!$arg) {
      help();
    }
  }
}
