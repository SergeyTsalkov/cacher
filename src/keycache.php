<?php
namespace Cacher2;

class KeyCache {
  private $map = [];

  function add(ItemSet $ItemSet) {
    foreach ($ItemSet as $Item) {
      $this->map[$Item->key] = $Item;
    }
  }

  function get(string $key): ?Item {
    return $this->map[$key] ?? null;
  }
}