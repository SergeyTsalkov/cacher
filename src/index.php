<?php
class CacherIndex {
  private $db;
  private ?string $username=null;
  private string $table='items';

  function __construct($handle, ?string $username=null) {
    if ($username) {
      $this->username = $username;
      $this->table = 'installed';
    }
    
    if ($handle instanceof MeekroDB) {
      $this->db = $handle;
    }
    else if (is_string($handle)) {
      if (! file_exists($handle)) touch($handle);
      chmod($handle, 0600);

      $this->db = new MeekroDB("sqlite:$handle");
    }
    else {
      throw new Exception("CacherIndex expects a MeekroDB object or sqlite filename");
    }

    $tables = $this->db->tableList();
    if (count($tables) == 0) {
      $structure_file = sprintf('%s/../structure-%s.sql', __DIR__, $this->db->dbType());
      $structure = file_get_contents($structure_file);
      $queries = array_filter(explode(';', $structure));

      foreach ($queries as $query) {
        $this->db->query($query);
      }
    }
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

    if ($this->username) {
      $item['is_symlink'] = $is_symlink ? 1 : 0;
      $item['username'] = $this->username;
      $this->db->replace($this->table, $item);
    } else {
      $this->db->insert($this->table, $item);
    }
  }

  // if key is string: return single item, either latest version or matching the given version
  // if key is array: return key->item hash, the latest version for each one
  function get($key, ?string $version=null) {
    $items = $this->getall($key, $version);
    if (! $items) {
      return is_array($key) ? [] : null;
    }

    if (! is_array($key)) {
      return $items[0];
    }

    $_results = [];
    foreach ($items as $item) {
      if (array_key_exists($item['key'], $_results)) continue;
      $_results[$item['key']] = $item;
    }
    return $_results;
  }

  // key is string or array, get all versions (sorted) for each match, return array
  function getall($key=null, ?string $version=null): array {
    $Where = new WhereClause('and');
    if ($this->username) $Where->add('username=%s', $this->username);

    if (is_string($key)) {
      if (strlen($key) == 0) return [];
      $Where->add('`key`=%s', $key);
    }
    else if (is_array($key)) {
      if (count($key) == 0) return [];
      $Where->add('`key` IN %ls', $key);
    }
    else if (!is_null($key)) {
      throw new Exception("key must be string, array, or null");
    }

    if ($version) $Where->add('version=%s', $version);

    $results = $this->db->query("SELECT * FROM %b WHERE %l", $this->table, $Where);
    if (! $results) return [];

    foreach ($results as &$row) {
      if (isset($row['files'])) {
        $row['files'] = json_decode($row['files'], true);
      }
      if (isset($row['is_symlink'])) {
        $row['is_symlink'] = intval($row['is_symlink']);
      }
    }

    usort($results, fn($a, $b) => version_compare($b['version'], $a['version']));
    return $results;
  }

  // match substring against keys, return key->item hash with latest item for each match
  function search(string $match=null) {
    $Where = new WhereClause('and');
    if ($this->username) $Where->add('username=%s', $this->username);
    if ($match) $Where->add('`key` LIKE %s', $match . '%');

    $results = $this->db->query("SELECT * FROM %b WHERE %l ORDER BY `key`", $this->table, $Where);
    $items = [];
    foreach ($results as $result) {
      $items[$result['key']][] = $result;
    }

    foreach ($items as $key => $versions) {
      usort($versions, fn($a, $b) => version_compare($b['version'], $a['version']));
      $items[$key] = $versions[0];
    }

    return $items;
  }

  function versions(string $key): array {
    $items = $this->getall($key);
    if (! $items) return [];

    return array_map(fn($item) => $item['version'], $items);
  }

  function version(string $key) {
    $versions = $this->versions($key);
    return $versions ? $versions[0] : null;
  }

  function delete(string $key, ?string $version=null) {
    $match = [];
    if ($this->username) $match['username'] = $this->username;
    $match['key'] = $key;
    if ($version) $match['version'] = $version;

    $this->db->delete($this->table, $match);
  }

  // if a given version has been available for at least 24 hours, any older versions
  // of the same key are "old" and can be purged
  function old() {
    $purge_after = 60*60*24; // 24 hours

    if ($this->username) {
      throw new Exception("old() shouldn't be used for installedIndex");
    }

    // a "settled" version is the latest version that has been available
    // for at least 24 hours, any older versions than that can be purged
    $settled = []; // key -> version
    $old = [];

    $items = $this->getall();
    foreach ($items as $item) {
      $key = $item['key'];
      if (isset($settled[$key])) continue;

      $created_at = strtotime($item['created_at']);
      if (time() - $created_at < $purge_after) continue;

      $settled[$key] = $item['version'];
    }

    foreach ($items as $item) {
      $key = $item['key'];
      $version = $item['version'];
      $settled_version = $settled[$key] ?? 0;

      if (version_compare($version, $settled_version) < 0) {
        $old[] = $item;
      }
    }

    return $old;
  }

  // installed items for all users
  function allinstalled() {
    return $this->db->query("SELECT * FROM installed");
  }

  function userdelete(string $username, string $key) {
    $this->db->delete('installed', ['username' => $username, 'key' => $key]);
  }

  function touch(string $key, string $version) {
    if ($this->username) {
      throw new Exception("touch() only works on remoteIndex and localIndex");
    }

    $this->db->query("UPDATE %b SET touched_at=CURRENT_TIMESTAMP 
      WHERE `key`=%s AND version=%s", $this->table, $key, $version);
  }

  function pdo() {
    return $this->db->get();
  }

}