<?php
use Symfony\Component\Filesystem\Filesystem;

class Cacher {
  private $s3;
  private $remoteIndex;
  private $localIndex;
  private $installedIndex;

  function __construct() {
    $db = new MeekroDB(
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

    $home = getenv('HOME');
    if (!$home || !is_dir($home)) throw new Exception("Unable to determine HOME");

    $local_index_file = $this->path_join($this->const('CACHER_HOME'), '.cacher2');
    $installed_index_file = $this->path_join($home, '.cacher2');
    $this->remoteIndex = new CacherIndex($db);
    $this->localIndex = new CacherIndex($local_index_file);
    $this->installedIndex = new CacherIndex($installed_index_file);
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

    $exists = $this->remoteIndex->get($key, $version);
    if ($exists) {
      throw new Exception("Remote cache already has $key ($version)");
    }
    
    $remote_path = $this->remote_path($key, $version);
    $Manager = new \Aws\S3\Transfer($this->s3, $path, $remote_path);
    $Manager->transfer();

    $this->remoteIndex->add($key, $version, $remote_path);
    $this->say("Pushed $key ($version) to $remote_path");
  }

  function pull(string $key) {
    $latest = $this->remoteIndex->get($key);
    if (! $latest) {
      throw new Exception("Item $key does not exist in the cache");
    }

    $remote_path = $latest['path'];
    $version = $latest['version'];
    $local_path = $this->local_path($key, $version);

    if ($exists = $this->localIndex->get($key, $version)) {
      throw new Exception("Local cache already has $key ($version) at {$exists['path']}");
    }

    if (is_dir($local_path) || file_exists($local_path)) {
      $fs = new Filesystem();
      $fs->remove($local_path);
    }

    mkdir($local_path, 0755, true);
    $Manager = new \Aws\S3\Transfer($this->s3, $remote_path, $local_path);
    $Manager->transfer();

    $files = $this->list_files($local_path);
    $this->localIndex->add($key, $version, $local_path, $files);
  }

  function localinfo() {
    return $this->localIndex->all();
  }

  function remoteinfo() {
    return $this->remoteIndex->all();
  }

  function install(string $key, string $path) {
    if (! is_dir($path)) {
      throw new Exception("$path is not a directory");
    }
    if (! is_writable($path)) {
      throw new Exception("$path is not writable");
    }

    $local = $this->localIndex->get($key);
    $installed = $this->installedIndex->get($key);
    if (! $local) {
      throw new Exception("Not available in local cache: $key");
    }
    if ($installed) {
      if ($installed['version'] == $local['version']) {
        $this->say("Already installed: $key ({$installed['version']})");
        return;
      }

      // remove any files that are part of installed, but not part of local
      $installed_files = $installed['files'];
      $local_files = $local['files'];
      $files_to_remove = array_diff($installed_files, $local_files);

      if ($files_to_remove) {
        $this->say("Removing old files from $path: ", join(', ', $files_to_remove));
        foreach ($files_to_remove as $file) {
          @unlink($this->path_join($path, $file));
        }
      }
    }

    shell_exec('rsync -a ' . escapeshellarg($local['path'] . '/') . ' ' . escapeshellarg($path));
    $this->installedIndex->update($key, $local['version'], $path, $local['files']);
    $this->say("Installed $key to $path");
  }

  function uninstall(string $key) {
    $installed = $this->installedIndex->get($key);
    if (! $installed) {
      throw new Exception("Not installed: $key");
    }

    $files = $installed['files'];
    foreach ($files as $file) {
      @unlink($this->path_join($installed['path'], $file));
    }

    $this->installedIndex->delete($key);
    $this->say("Uninstalled $key");
  }

  function installed() {
    return $this->installedIndex->all();
  }

  private function list_files(string $basedir): array {
    if (! is_dir($basedir)) {
      throw new Exception("$basedir is not a directory");
    }

    $result = [];
    $Dir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($basedir, FilesystemIterator::SKIP_DOTS));
    foreach ($Dir as $File) {
      if (! $File->isFile()) continue;

      $path = $File->getPathname();
      if (! str_starts_with($path, $basedir)) {
        throw new Exception("This path does not start with $basedir: $path");
      }

      $relpath = substr($path, strlen($basedir));
      $relpath = ltrim($relpath, '/');
      $result[] = $relpath;
    }
    return $result;
  }

  private function item2path(string $key) {
    if (preg_match('/[^\w\-:]/i', $key)) {
      throw new Exception("cache key contains invalid characters: $key");
    }

    $path = str_replace(':', '/', $key);
    return $path;
  }

  private function remote_path(string $key, string $version) {
    $cache_path = sprintf('s3://%s/', $this->const('CACHER_R2_BUCKET'));
    return $this->path_join($cache_path, $this->item2path($key), $version);
  }

  private function local_path(string $key, string $version) {
    return $this->path_join($this->const('CACHER_HOME'), $this->item2path($key), $version);
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

  function say(string ...$messages) {
    $is_console = php_sapi_name() == 'cli';

    if ($is_console) {
      echo join(' ', $messages), "\n";
    }
  }

}