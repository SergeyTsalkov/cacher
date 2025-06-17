<?php
namespace Cacher2;

class ItemSet implements \Iterator, \Countable {
  private $i = 0;
  private array $Items=[];

  function add(string $key, ItemVersion $IV) {
    if (! isset($this->Items[$key])) {
      $this->Items[$key] = new Item($key); 
    }

    $this->Items[$key]->add($IV);
  }

  function get(string $key) {
    return $this->Items[$key] ?? null;
  }

  function keys(): array {
    return array_keys($this->Items);
  }

  function count(): int { return count($this->Items); }
  #[\ReturnTypeWillChange]
  function rewind() { $this->i = 0; }
  #[\ReturnTypeWillChange]
  function current() { return array_values($this->Items)[$this->i]; }
  #[\ReturnTypeWillChange]
  function key() { return $this->i; }
  #[\ReturnTypeWillChange]
  function next() { $this->i++; }
  #[\ReturnTypeWillChange]
  function valid() { return count($this->Items) > $this->i; }
}

class Item implements \Iterator {
  public string $key;
  private array $Versions=[];
  private $i = 0;

  function __construct(string $key) {
    $this->key = $key;
  }

  function add(ItemVersion $Version) {
    $Version->key = $this->key;
    $this->Versions[] = $Version;
    usort($this->Versions, fn($a, $b) => version_compare($b->version, $a->version));
  }

  function get($want=null): ?ItemVersion {
    foreach ($this->Versions as $Version) {
      if (is_null($want)) return $Version;
      if ($Version->version == $want) return $Version;
    }
  }

  function versions() {
    return array_map(fn($IV) => $IV->version, $this->Versions);
  }

  function version() {
    if ($this->Versions) {
      return $this->versions()[0];
    }
  }

  #[\ReturnTypeWillChange]
  function rewind() { $this->i = 0; }
  #[\ReturnTypeWillChange]
  function current() { return $this->Versions[$this->i]; }
  #[\ReturnTypeWillChange]
  function key() { return $this->i; }
  #[\ReturnTypeWillChange]
  function next() { $this->i++; }
  #[\ReturnTypeWillChange]
  function valid() { return count($this->Versions) > $this->i; }
}

class ItemVersion {
  public string $key;
  public string $version;
  public string $path;
  public int $created_at;

  public array $files=[];
  public bool $is_symlink=false;
  
  function __construct(string $version, string $path, int $created_at) {
    $this->version = $version;
    $this->path = $path;
    $this->created_at = $created_at;
  }
}