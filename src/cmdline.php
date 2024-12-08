<?php
class ParsedCommandLine {
  private $flags = [];
  private $args = [];

  function __construct(array $argv) {
    array_shift($argv);

    foreach ($argv as $arg) {
      if (str_starts_with($arg, '-')) {
        $arg = ltrim($arg, '-');

        if (str_contains($arg, '=')) {
          list($flag, $value) = explode('=', $arg, 2);
          $this->flags[$flag] = $value;
        } else {
          $this->flags[$arg] = true;
        }
      } else {
        $this->args[] = $arg;
      }
    }
  }

  function flag($flag) {
    return in_array($flag, $this->flags);
  }

  function arg(int $index) {
    return $this->args[$index] ?? null;
  }

  function args(int $start, int $end=null) {
    if ($end === null) {
      return array_slice($this->args, $start);
    }

    $array = array_slice($this->args, $start, $end);
    $array = array_pad($array, $end - $start + 1, null);
    return $array;
  }

  function countArgs() {
    return count($this->args);
  }

  function countFlags() {
    return count($this->flags);
  }
}
