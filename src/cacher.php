<?php
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\SemaphoreStore;
use Symfony\Component\Lock\Store\PdoStore;

// TODO: 
// * if we have a username, we must also be root and the username must be real
// * sudo to username when installing (in production)
// * install by symlink
// * copy (install without mentioning in database)
// * use touch()

class Cacher {
  private $s3;
  private ?string $username;
  private $remoteIndex;
  private $localIndex;
  private $installedIndex;

  function __construct(?string $username=null) {
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
    $this->remoteIndex = new CacherIndex($db);
    $this->localIndex = new CacherIndex($local_index_file);

    if ($username) {
      $this->username = $username;
      $this->installedIndex = new CacherIndex($local_index_file, $username);
    }
  }

  function localUpToDate(string $key) {
    $remote = $this->remoteIndex->get($key);
    $local = $this->localIndex->get($key);

    if (!$local || !$remote) return false;
    return $local['version'] == $remote['version'];
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
    $Lock = $this->lock("key:$key");

    $latest = $this->remoteIndex->get($key);
    if (! $latest) {
      throw new Exception("Item $key does not exist in the cache");
    }

    $remote_path = $latest['path'];
    $version = $latest['version'];
    $local_path = $this->local_path($key, $version);

    if ($exists = $this->localIndex->get($key, $version)) {
      $this->say("Local cache already has $key ($version)");
      return;
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
    $this->say("Pulled $key ($version)");
  }

  function deletelocal(string $key, string $version=null) {
    if (is_null($version)) {
      $versions = $this->localIndex->versions($key);
      if (! $versions) {
        throw new Exception("Item $key does not exist in local cache");
      }
      foreach ($versions as $version) {
        $this->deletelocal($key, $version);
      }
      return;
    }

    $item = $this->localIndex->get($key, $version);
    if (! $item) {
      throw new Exception("Item $key ($version) does not exist in local cache");
    }

    $fs = new Filesystem();
    $fs->remove($item['path']);
    $this->localIndex->delete($key, $version);
    $this->say("Deleted $key ($version) from local cache");
  }

  function cleanlocal() {
    $this->say("Cleaning local cache..");
    foreach ($this->localIndex->old() as $item) {
      $this->deletelocal($item['key'], $item['version']);
    }
  }

  function deleteremote(string $key, string $version=null) {
    if (is_null($version)) {
      $versions = $this->remoteIndex->versions($key);
      if (! $versions) {
        throw new Exception("Item $key does not exist in remote cache");
      }
      foreach ($versions as $version) {
        $this->deleteremote($key, $version);
      }
      return;
    }

    // trailing slash is important so that path/1 doesn't match path/11
    $remote_path = $this->remote_path($key, $version, true) . '/';
    $this->s3->deleteMatchingObjects($this->const('CACHER_R2_BUCKET'), $remote_path);
    $this->remoteIndex->delete($key, $version);
    $this->say("Deleted $key ($version) from remote cache");
  }

  function cleanremote() {
    $this->say("Cleaning remote cache..");
    foreach ($this->remoteIndex->old() as $item) {
      $this->deleteremote($item['key'], $item['version']);
    }
  }

  function localinfo(string $match=null) {
    return $this->localIndex->search($match);
  }

  function remoteinfo(string $match=null) {
    return $this->remoteIndex->search($match);
  }

  function install(string $key, string $path) {
    if (! $this->username) {
      throw new Exception("username is missing");
    }

    $Lock = $this->lock("username:{$this->username}");

    if (! is_dir($path)) {
      throw new Exception("$path is not a directory");
    }
    if (! is_writable($path)) {
      throw new Exception("$path is not writable");
    }

    if (! $this->localUpToDate($key)) {
      $this->pull($key);
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
    $this->installedIndex->add($key, $local['version'], $path, $local['files']);
    $this->say("Installed $key to $path");
  }

  function uninstall(string $key) {
    if (! $this->username) {
      throw new Exception("username is missing");
    }
    $Lock = $this->lock("username:{$this->username}");

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
    return $this->installedIndex->search();
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

  private function remote_path(string $key, string $version, bool $use_simple=false) {
    $simple_path = $this->path_join($this->item2path($key), $version);
    if ($use_simple) return $simple_path;

    $cache_path = sprintf('s3://%s/', $this->const('CACHER_R2_BUCKET'));
    return $this->path_join($cache_path, $simple_path);
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

  private function lock(string $name) {
    $Factory = new LockFactory(new PdoStore($this->localIndex->pdo()));
    $Lock = $Factory->createLock($name);
    $Lock->acquire(true);
    return $Lock;
  }

  function say(string ...$messages) {
    $is_console = php_sapi_name() == 'cli';

    if ($is_console) {
      echo join(' ', $messages), "\n";
    }
  }

}