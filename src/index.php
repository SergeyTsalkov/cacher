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
      $structure_file = sprintf('%s/../structure-%s', __DIR__, $this->db->db_type());
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

  function version(string $key) {
    $versions = $this->db->queryFirstColumn("SELECT version FROM items WHERE `key`=%s", $key);
    if (! $versions) return;

    usort($versions, fn($a, $b) => version_compare($b, $a));
    return $versions[0];
  }

  function get(string $key, ?string $version=null) {
    if (! $version) $version = $this->version($key);
    if (! $version) return;

    return $this->db->queryFirstRow("SELECT * FROM items WHERE `key`=%s AND version=%s", $key, $version);
  }


}