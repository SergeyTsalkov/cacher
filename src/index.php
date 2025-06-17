<?php
namespace Cacher2;
use \Exception;
use \MeekroDB;
use \WhereClause;

class CacherIndex {
  private $db;
  private ?string $username=null;
  private string $table='items';
  private string $type;
  private ?KeyCache $KeyCache=null;

  function __construct(string $type, $handle, ?string $username=null) {
    $this->type = $type;
    if (!in_array($type, ['remote', 'local', 'installed'])) {
      throw new Exception("invalid index type");
    }
    if ($this->isInstalled()) {
      $this->username = $username;
      $this->table = 'installed';
    }
    
    if ($handle instanceof MeekroDB) {
      if (!$this->isRemote()) {
        throw new Exception("MeekroDB handle only appropriate for remote index");
      }
      $this->db = $handle;
    }
    else if (is_string($handle)) {
      if ($this->isRemote()) {
        throw new Exception("string handle not appropriate for remote index");
      }

      if (! file_exists($handle)) touch($handle);
      chmod($handle, 0600);

      $this->db = new MeekroDB("sqlite:$handle");
    }
    else {
      throw new Exception("CacherIndex expects a MeekroDB object or sqlite filename");
    }

    if ($this->isRemote()) {
      $this->KeyCache = new KeyCache();
      // $this->db->debugMode();
    }

    $tables = $this->db->tableList();
    if (count($tables) == 0) {
      $structure_file = sprintf('%s/../structure-%s.sql', __DIR__, $this->db->dbType());
      $structure = file_get_contents($structure_file);
      $queries = array_filter(array_map('trim', explode(';', $structure)));

      foreach ($queries as $query) {
        $this->db->query($query);
      }
    }
  }

  function isRemote() { return $this->type == 'remote'; }
  function isLocal() { return $this->type == 'local'; }
  function isInstalled() { return $this->type == 'installed'; }

  function lusers(): array {
    if (! $this->isInstalled()) {
      throw new Exception("lusers is only available for the installed index");
    }

    return $this->db->queryFirstColumn("SELECT DISTINCT username FROM %b", $this->table);
  }

  function setUser(string $username) {
    if (! $this->isInstalled()) {
      throw new Exception("setUser is only available for the installed index");
    }

    $this->username = $username;
  }

  function add(string $key, string $version, string $path, ?array $files=[], bool $is_symlink=false) {
    $item = [
      'key' => $key,
      'version' => $version,
      'path' => $path,
    ];
    if ($files) {
      $item['files'] = json_encode($files);
    }

    if ($this->isInstalled()) {
      $item['is_symlink'] = $is_symlink ? 1 : 0;
      $item['username'] = $this->username;
      $this->db->replace($this->table, $item);
    } else {
      $this->db->insert($this->table, $item);
    }
  }

  function get(string $key): ?Item {
    if ($this->KeyCache && ($Item = $this->KeyCache->get($key))) {
      return $Item;
    }

    $ItemSet = $this->search($key);
    return $ItemSet->get($key);
  }

  function getIV(string $key, string $version=null): ?ItemVersion {
    $Item = $this->get($key);
    if ($Item) return $Item->get($version);
    return null;
  }

  function search($key=null, bool $substring=false): ItemSet {
    $ItemSet = new ItemSet();

    $Where = new WhereClause('and');
    if ($this->isInstalled()) $Where->add('username=%s', $this->username);
    
    if (is_string($key)) {
      if (strlen($key) == 0) return $ItemSet;
      if ($substring) $Where->add('`key` LIKE %s', $key . '%'); 
      else $Where->add('`key`=%s', $key);
    }
    else if (is_array($key)) {
      if (count($key) == 0) return $ItemSet;
      $Where->add('`key` IN %ls', $key);
    }
    else if (!is_null($key)) {
      throw new Exception("key must be string, array, or null");
    }

    if ($this->db->dbType() == 'sqlite') {
      $ts_block = "strftime('%s', created_at) as created_at_ts";
    } else {
      $ts_block = "unix_timestamp(created_at) as created_at_ts";
    }

    $results = $this->db->query("SELECT *,%l FROM %b WHERE %l", $ts_block, $this->table, $Where);
    foreach ($results as $result) {
      $IV = new ItemVersion($result['version'], $result['path'], $result['created_at_ts']);
      if (isset($result['is_symlink'])) $IV->is_symlink = !!$result['is_symlink'];
      if (isset($result['files'])) {
        $IV->files = json_decode($result['files'], true);
      }

      $ItemSet->add($result['key'], $IV);
    }

    if ($this->KeyCache) {
      $this->KeyCache->add($ItemSet);
    }

    return $ItemSet;
  }

  function versions(string $key): array {
    $Item = $this->get($key);
    if (! $Item) return [];
    return $Item->versions();
  }

  function version(string $key) {
    $versions = $this->versions($key);
    return $versions ? $versions[0] : null;
  }

  function delete(string $key, ?string $version=null) {
    $match = [];
    if ($this->isInstalled()) $match['username'] = $this->username;
    $match['key'] = $key;
    if ($version) $match['version'] = $version;

    $this->db->delete($this->table, $match);
  }

  // if a given version has been available for at least 24 hours, any older versions
  // of the same key are "old" and can be purged
  function old(): array {
    $purge_after = 60*60*24; // 24 hours

    if ($this->isInstalled()) {
      throw new Exception("old() shouldn't be used for installedIndex");
    }

    // a "settled" version is the latest version that has been available
    // for at least 24 hours, any older versions than that can be purged
    $settled = []; // key -> version
    $old = [];

    $Items = $this->search();
    foreach ($Items as $Item) {
      foreach ($Item as $IV) {
        if (time() - $IV->created_at < $purge_after) continue;

        $settled[$Item->key] = $IV->version;
        continue 2;
      }
    }

    foreach ($Items as $Item) {
      $key = $Item->key;
      $settled_version = $settled[$key] ?? 0;

      foreach ($Item as $IV) {
        if (version_compare($IV->version, $settled_version) < 0) {
          $old[] = $IV;
        }
      }
    }

    return $old;
  }

  function touch(string $key, string $version) {
    if (!$this->isLocal()) {
      throw new Exception("touch() only works on localIndex");
    }

    $this->db->query("UPDATE %b SET touched_at=CURRENT_TIMESTAMP 
      WHERE `key`=%s AND version=%s", $this->table, $key, $version);
  }

  function pdo() {
    return $this->db->get();
  }

}