<?php
class Cacher {
  private $db;
  private $s3;

  function __construct() {
    $this->db = new MeekroDB(
      $this->const('CACHER_DB_DSN'), 
      $this->const('CACHER_DB_USER'), 
      $this->const('CACHER_DB_PASS')
    );

    $endpoint = sprintf('https://%s.r2.cloudflarestorage.com', $this->const('CACHER_R2_ACCOUNT'));
    $this->s3 = new \Aws\S3\S3Client([
        'region'  => 'auto',
        'endpoint' => $endpoint,
        'version' => 'latest',
        'credentials' => [
          'key' => $this->const('CACHER_R2_KEY'),
          'secret' => $this->const('CACHER_R2_SECRET'),
        ],
    ]);
  }

  function push(string $path, string $key, string $version=null) {
    if (! is_dir($path)) {
      throw new Exception("$path is not a directory");
    }
    if (! is_readable($path)) {
      throw new Exception("$path is not readable");
    }
    if ($this->dir_is_empty($path)) {
      throw new Exception("$path has no files in it");
    }

    if (! $version) $version = time();
    $remote_path = $this->remote_cache_path_version($key, $version);

    // make sure our DB connection is okay
    $this->db->get();

    $Manager = new \Aws\S3\Transfer($this->s3, $path, $remote_path);
    $Manager->transfer();

    $this->db->insert('items', [
      'key' => $key,
      'path' => $remote_path,
      'version' => $version,
    ]);
  }

  private function item2path(string $key) {
    if (preg_match('/[^\w\-:]/i', $key)) {
      throw new Exception("cache key contains invalid characters: $key");
    }

    $path = str_replace(':', '/', $key);
    return $path;
  }

  private function remote_cache_path_version(string $key, string $version) {
    $cache_path = sprintf('s3://%s/', $this->const('CACHER_R2_BUCKET'));
    return $this->path_join($cache_path, $this->item2path($key), $version);
  }

  private function const(string $name) {
    if (! defined($name)) {
      throw new Exception("Constant not found: $name");
    }

    return constant($name);
  }

  private function dir_is_empty(string $dir) {
    $list = glob("{$dir}/*");
    return count($list) == 0;
  }

  private function path_join(string ...$parts): string {
    if (count($parts) == 0) return '';
    
    foreach ($parts as $i => &$part) {
      if (strlen($part) == 0) throw new Exception("path_join: part can't be empty");
      if ($i == 0) $part = rtrim($part, '/');
      else $part = trim($part, '/');
    }

    return join('/', $parts);
  }
}