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

  function add(string $key, string $version, string $path, array $files=[]) {
    $item = [
      'key' => $key,
      'version' => $version,
      'path' => $path,
    ];
    if ($files) {
      $item['files'] = json_encode($files);
    }

    if ($this->username) {
      $item['username'] = $this->username;
      $this->db->replace($this->table, $item);
    } else {
      $this->db->insert($this->table, $item);
    }
  }

  function get(string $key, ?string $version=null) {
    $results = $this->getall($key, $version);
    return $results ? $results[0] : null;
  }

  function getall(string $key, ?string $version=null): array {
    $match = [];
    if ($this->username) $match['username'] = $this->username;
    $match['key'] = $key;
    if ($version) $match['version'] = $version;

    $results = $this->db->query("SELECT * FROM %b WHERE %ha", $this->table, $match);
    if (! $results) return [];

    foreach ($results as &$row) {
      if (isset($row['files'])) {
        $row['files'] = json_decode($row['files'], true);
      }
    }

    usort($results, fn($a, $b) => version_compare($b['version'], $a['version']));
    return $results;
  }

  function versions(string $key) {
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

  // return all items that are at least 24 hours older than the newest version of the same key
  function old() {
    $Where = new WhereClause('and');
    if ($this->username) {
      $Where->add('username=%s', $this->username);
    }
    $results = $this->db->query("SELECT * FROM %b WHERE %l ORDER BY `key`", $this->table, $Where);
    
    $items = [];
    $newest = [];
    
    // Group by key and find newest version
    foreach ($results as $result) {
      $items[$result['key']][] = $result;
    }
    foreach ($items as $key => $versions) {
      usort($versions, fn($a, $b) => version_compare($b['version'], $a['version']));
      $newest[$key] = $versions[0];
    }

    $old_items = [];
    foreach ($items as $key => $versions) {
      $newest_time = strtotime($newest[$key]['created_at']);
      
      foreach ($versions as $version) {
        if ($version === $newest[$key]) continue;
        
        $version_time = strtotime($version['created_at']);
        if ($newest_time - $version_time >= 24 * 60 * 60) {
          $old_items[] = $version;
        }
      }
    }

    return $old_items;
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