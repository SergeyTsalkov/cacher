<?php
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\SemaphoreStore;
use Symfony\Component\Lock\Store\PdoStore;

// TODO: 
// * cleanlocal should do more, as planned

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

    $remote = $this->remoteIndex->get($key);
    if (! $remote) {
      throw new Exception("Item $key does not exist in the cache");
    }
    $this->remoteIndex->touch($key, $remote['version']);

    $version = $remote['version'];
    $local_versions = $this->localIndex->versions($key);
    foreach ($local_versions as $local_version) {
      if (version_compare($local_version, $version) > 0) {
        $this->say("Local version of $key ($local_version) is ahead of remote version $version, removing local..");
        $this->deletelocal($key, $local_version);
      }
      else if (version_compare($local_version, $version) == 0) {
        $this->say("Local cache already has $key ($version)");
        return;
      }
    }
    
    $local_path = $this->local_path($key, $version);
    if (is_dir($local_path) || file_exists($local_path)) {
      $fs = new Filesystem();
      $fs->remove($local_path);
    }

    mkdir($local_path, 0755, true);
    $Manager = new \Aws\S3\Transfer($this->s3, $remote['path'], $local_path);
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

  function remoteinfo(string $match=null): array {
    $results = [];
    $items = $this->remoteIndex->search($match);
    foreach ($items as $key => $remote_item) {
      $results[$key] = [
        'version' => $remote_item['version'],
      ];
    }
    return $results;
  }

  function localinfo(string $match=null): array {
    $local = $this->localIndex->search($match);
    $keys = array_map(fn($item) => $item['key'], $local);
    $remote = $this->remoteIndex->get($keys);

    $results = [];
    foreach ($local as $key => $local_item) {
      $remote_item = $remote[$key] ?? null;
      $up_to_date = !$remote_item || ($remote_item['version'] == $local_item['version']);

      $results[$key] = [
        'local_version' => $local_item['version'],
        'remote_version' => $remote_item['version'] ?? null,
        'up_to_date' => $up_to_date,
      ];
    }

    return $results;
  }

  function installedinfo(): array {
    $installed = $this->installedIndex->search();
    $keys = array_map(fn($item) => $item['key'], $installed);
    $local = $this->localIndex->get($keys);
    $remote = $this->remoteIndex->get($keys);

    $results = [];
    foreach ($installed as $key => $installed_item) {
      $local_item = $local[$key] ?? null;
      $remote_item = $remote[$key] ?? null;

      $up_to_date = true;
      if ($local_item && $installed_item['version'] != $local_item['version']) {
        $up_to_date = false;
      }
      if ($remote_item && $installed_item['version'] != $remote_item['version']) {
        $up_to_date = false;
      }

      $results[$key] = [
        'path' => $installed_item['path'],
        'is_symlink' => !!$installed_item['is_symlink'],
        'installed_version' => $installed_item['version'],
        'local_version' => $local_item['version'] ?? null,
        'remote_version' => $remote_item['version'] ?? null,
        'up_to_date' => $up_to_date,
      ];
    }
    return $results;
  }

  function installed(): array {
    return $this->installedIndex->search();
  }

  // used by install, upgrade, copy
  private function _install(string $key, ?string $path=null, bool $copy_only=false, bool $use_symlink=false) {
    $installed = null;
    if (! $copy_only) {
      $installed = $this->installedIndex->get($key);
      if ($installed) {
        $path = $installed['path'];
      }
    }
    
    if (!$path) {
      throw new Exception("Path not specified");
    }
    if (!is_dir($path) || !is_writable($path)) {
      throw new Exception("Path doesn't look valid: $path");
    }

    if (! $this->localUpToDate($key)) {
      $this->pull($key);
    }
    $local = $this->localIndex->get($key);
    if (! $local) {
      throw new Exception("Unable to find: $key");
    }

    if ($installed) {
      if ($installed['version'] == $local['version']) {
        $this->say("Already latest version: $key ({$installed['version']})");
        return;
      }

      // remove any files that are part of installed, but not part of local
      $installed_files = $installed['files'];
      $local_files = $local['files'];
      $files_to_remove = array_diff($installed_files, $local_files);

      if ($files_to_remove) {
        $this->remove_files($path, $files_to_remove);
      }
    }

    $this->localIndex->touch($key, $local['version']);

    if ($use_symlink) {
      $this->remove_files($path, $local['files']);
      $this->sudo($this->username, 'cp', ['-sr', $local['path'] . '/.', $path]);
    } else {
      $this->sudo($this->username, 'rsync', ['-a', $local['path'] . '/', $path]);
    }
    
    if (! $copy_only) {
      $this->installedIndex->add($key, $local['version'], $path, $local['files'], $use_symlink);
      $this->say("Installed $key ({$local['version']}) to $path");
    } else {
      $this->say("Copied $key ({$local['version']}) to $path");
    }
  }

  function copy(string $key, string $path) {
    $Lock = $this->lock("username:{$this->username}");
    $this->_install($key, $path, true);
  }

  function install(string $key, string $path, $use_symlink=false) {
    $Lock = $this->lock("username:{$this->username}");

    $installed = $this->installedIndex->get($key);
    if ($installed) {
      throw new Exception("Already installed: $key");
    }

    $this->_install($key, $path, false, $use_symlink);
  }

  function upgrade(?string $key=null) {
    if (! $key) {
      foreach ($this->installed() as $item) {
        $this->upgrade($item['key']);
      }
      return;
    }

    $Lock = $this->lock("username:{$this->username}");
    $installed = $this->installedIndex->get($key);
    if (! $installed) {
      throw new Exception("Not installed: $key");
    }

    $this->_install($key, null, false, $installed['is_symlink']);
  }

  function uninstall(string $key) {
    $Lock = $this->lock("username:{$this->username}");

    $installed = $this->installedIndex->get($key);
    if (! $installed) {
      throw new Exception("Not installed: $key");
    }

    $files_to_remove = $installed['files'];
    if ($files_to_remove) {
      $this->remove_files($installed['path'], $files_to_remove);
    }

    $this->installedIndex->delete($key);
    $this->say("Uninstalled $key");
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

  private function remove_files(string $basedir, array $files) {
    if (! is_dir($basedir)) {
      throw new Exception("$basedir is not a directory");
    }
    if (! $files) return;

    $basedir = realpath($basedir);
    $dirs = [];
    foreach ($files as &$file) {
      $file = $this->path_join($basedir, $file);
      $dir = realpath(dirname($file));
      if ($basedir != $dir) {
        $dirs[] = $dir;
      }
    }
    $this->sudo($this->username, 'rm -f', $files);

    $dirs = array_unique($dirs);
    $dirs = array_filter($dirs, fn($dir) => $this->dir_is_empty($dir));
    if ($dirs) {
      $this->sudo($this->username, 'rmdir', $dirs);
    }
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

  private function sudo(string $username, string $command, array $args) {
    $args = array_map('escapeshellarg', $args);
    $command = array_merge([$command], $args);
    $command = implode(' ', $command);

    if (! defined('CACHER_IS_DEVELOPMENT')) {
      if (posix_geteuid() != 0) {
        throw new Exception("Unable to sudo to $username: we are not root");
      }
      if (! posix_getpwnam($username)) {
        throw new Exception("Unable to sudo to $username: user does not exist");
      }

      $this->sayf('[sudo %s]: %s', $username, $command);
      $command = sprintf('sudo -Hn -u %s bash -c %s', escapeshellarg($username), escapeshellarg($command));
    } else {
      $this->say($command);
    }

    shell_exec($command);
  }

  function sayf(string $str, string ...$args) {
    return $this->say(sprintf($str, ...$args));
  }

  function say(string ...$messages) {
    $is_console = php_sapi_name() == 'cli';

    if ($is_console) {
      echo join(' ', $messages), "\n";
    }
  }

}