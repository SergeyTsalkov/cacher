<?php
class CacherIndex {
  private $db;

  function __construct($handle) {
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
    if (count($tables) > 1) {
      throw new Exception("More than one table?!? Database seems corrupted!");
    }

    if (count($tables) == 0) {
      $structure_file = sprintf('%s/../structure-%s.sql', __DIR__, $this->db->dbType());
      $structure = file_get_contents($structure_file);
      $this->db->query($structure);
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

    $this->db->insert('items', $item);
  }

  function versions(string $key) {
    $versions = $this->db->queryFirstColumn("SELECT version FROM items WHERE `key`=%s", $key);
    if (! $versions) return;

    usort($versions, fn($a, $b) => version_compare($b, $a));
    return $versions;
  }

  function version(string $key) {
    $versions = $this->versions($key);
    return $versions ? $versions[0] : null;
  }

  function get(string $key, ?string $version=null) {
    if (! $version) $version = $this->version($key);
    if (! $version) return;

    $row = $this->db->queryFirstRow("SELECT * FROM items WHERE `key`=%s AND version=%s", $key, $version);
    if (! $row) return;

    if (isset($row['files'])) {
      $row['files'] = json_decode($row['files'], true);
    }
    return $row;
  }
  
  // remove any other versions of this key, and add the new version
  function update(string $key, string $version, string $path, array $files=[]) {
    $this->db->startTransaction();
    $this->delete($key);
    $this->add($key, $version, $path, $files);
    $this->db->commit();
  }

  function delete(string $key, ?string $version=null) {
    if ($version) {
      $this->db->delete('items', ['key' => $key, 'version' => $version]);
    } else {
      $this->db->delete('items', ['key' => $key]);
    }
  }

  function all(string $match=null) {
    if ($match) {
      $results = $this->db->query("SELECT * FROM items WHERE `key` LIKE %s ORDER BY `key`", $match . '%');
    } else {
      $results = $this->db->query("SELECT * FROM items ORDER BY `key`");
    }

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
    $results = $this->db->query("SELECT * FROM items ORDER BY `key`");
    
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

}