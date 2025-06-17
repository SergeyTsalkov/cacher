<?php
namespace Cacher2;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\PdoStore;
use \Exception;
use \MeekroDB;
use \Aws\S3\S3Client;
use \RecursiveIteratorIterator;
use \RecursiveDirectoryIterator;
use \FilesystemIterator;


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
    $this->s3 = new S3Client([
        'region'  => 'auto',
        'endpoint' => $endpoint,
        'version' => 'latest',
        'suppress_php_deprecation_warning' => true,
        'credentials' => [
          'key' => $this->const('CACHER_R2_KEY'),
          'secret' => $this->const('CACHER_R2_SECRET'),
        ],
    ]);

    $home = $this->const('CACHER_HOME');
    if (! is_dir($home)) @mkdir($home, 0755, true);
    if (! is_dir($home)) throw new Exception("CACHER_HOME dir ($home) doesn't exist");

    $this->username = $username;
    $local_index_file = $this->path_join($home, '.cacher2');
    $this->remoteIndex = new CacherIndex('remote', $db);
    $this->localIndex = new CacherIndex('local', $local_index_file);
    $this->installedIndex = new CacherIndex('installed', $local_index_file, $username);
  }

  function localUpToDate(string $key) {
    $Remote = $this->remoteIndex->getIV($key);
    $Local = $this->localIndex->getIV($key);

    if (!$Local || !$Remote) return false;
    return $Local->version == $Remote->version;
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

    if ($this->remoteIndex->getIV($key, $version)) {
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

    $Remote = $this->remoteIndex->getIV($key);
    if (! $Remote) {
      throw new Exception("Item $key does not exist in the cache");
    }

    $version = $Remote->version;
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
    $Manager = new \Aws\S3\Transfer($this->s3, $Remote->path, $local_path);
    $Manager->transfer();

    $files = $this->list_files($local_path);
    $this->localIndex->add($key, $version, $local_path, $files);
    $this->say("Pulled $key ($version)");
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

    $Item = $this->remoteIndex->getIV($key, $version);
    if (!$Item) {
      throw new Exception("Item $key ($version) does not exist in remote cache");
    }

    // trailing slash is important so that path/1 doesn't match path/11
    list($bucket, $remote_path) = $Item->splitPath();
    $this->s3->deleteMatchingObjects($bucket, $remote_path . '/');
    $this->remoteIndex->delete($key, $version);
    $this->say("Deleted $key ($version) from remote cache");
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

    $Item = $this->localIndex->getIV($key, $version);
    if (! $Item) {
      throw new Exception("Item $key ($version) does not exist in local cache");
    }

    $fs = new Filesystem();
    $fs->remove($Item->path);
    $this->localIndex->delete($key, $version);
    $this->say("Deleted $key ($version) from local cache");
  }

  function cleanlocal() {
    // installed items with is_symlink set are "used"
    // meaning we can't remove the localIndex version
    $used = [];

    // delete installed items that don't exist on disk anymore
    $users = $this->installedIndex->lusers();
    foreach ($users as $user) {
      $this->installedIndex->setUser($user);

      $Installed = $this->installedIndex->search();
      foreach ($Installed as $Item) {
        foreach ($Item as $IV) {
          if (!is_dir($IV->path)) {
            $this->sayf("Deleting dead installed item: %s (%s)", $IV->key, $user);
            $this->installedIndex->delete($IV->key);
            continue;
          }

          if ($IV->is_symlink) {
            $used[$IV->key][$IV->version] = true;
          }
        }
      }
    }

    foreach ($this->localIndex->old() as $IV) {
      $key = $IV->key;
      $version = $IV->version;

      if (isset($used[$key][$version])) continue;
      $this->deletelocal($key, $version);
    }
  }

  function cleanremote() {
    $this->say("Cleaning remote cache..");
    foreach ($this->remoteIndex->old() as $IV) {
      $this->deleteremote($IV->key, $IV->version);
    }
  }

  function remoteinfo(string $match=null, bool $exact = false): array {
    $results = [];
    $ItemSet = $this->remoteIndex->search($match, !$exact);
    foreach ($ItemSet as $Item) {
      $results[$Item->key] = [
        'version' => $Item->version(),
        'created_at' => $Item->get()->created_at,
      ];
    }
    return $results;
  }

  function localinfo(string $match=null, bool $exact = false): array {
    $Local = $this->localIndex->search($match, !$exact);
    $Remote = $this->remoteIndex->search($Local->keys());

    $results = [];
    foreach ($Local as $LocalItem) {
      $key = $LocalItem->key;
      $remote_version = null;
      if ($RemoteItem = $Remote->get($key)) {
        $remote_version = $RemoteItem->version();
      }
      
      $results[$key] = [
        'local_version' => $LocalItem->version(),
        'remote_version' => $remote_version,
        'up_to_date' => $LocalItem->version() == $remote_version,
      ];
    }

    return $results;
  }

  function installedinfo(): array {
    $Installed = $this->installedIndex->search();
    $Local = $this->localIndex->search($Installed->keys());
    $Remote = $this->remoteIndex->search($Installed->keys());

    $results = [];
    foreach ($Installed as $Item) {
      $IV = $Item->get();
      $key = $Item->key;

      $up_to_date = true;
      $LocalItem = $Local->get($key);
      $RemoteItem = $Remote->get($key);

      if ($LocalItem && $Item->version() != $LocalItem->version()) {
        $up_to_date = false;
      }
      if ($RemoteItem && $Item->version() != $RemoteItem->version()) {
        $up_to_date = false;
      }

      $results[$key] = [
        'path' => $IV->path,
        'is_symlink' => $IV->is_symlink,
        'installed_version' => $IV->version,
        'local_version' => $LocalItem ? $LocalItem->version() : null,
        'remote_version' => $RemoteItem ? $RemoteItem->version() : null,
        'up_to_date' => $up_to_date,
      ];
    }
    return $results;
  }

  // used by install, upgrade, copy
  private function _install(string $key, ?string $path=null, bool $copy_only=false, bool $use_symlink=false) {
    $InstalledItem = null;
    if (! $copy_only) {
      $InstalledItem = $this->installedIndex->getIV($key);
      if ($InstalledItem) {
        $path = $InstalledItem->path;
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
    $LocalItem = $this->localIndex->getIV($key);
    if (! $LocalItem) {
      throw new Exception("Unable to find: $key");
    }

    if ($InstalledItem) {
      if ($InstalledItem->version == $LocalItem->version) {
        $this->sayf("Already latest version: %s (%s)", $key, $InstalledItem->version);
        return;
      }

      // remove any files that are part of installed, but not part of local
      $files_to_remove = array_diff($InstalledItem->files, $LocalItem->files);

      if ($files_to_remove) {
        $this->remove_files($path, $files_to_remove);
      }
    }

    $this->localIndex->touch($key, $LocalItem->version);

    if ($use_symlink) {
      $this->remove_files($path, $LocalItem->files);
      $this->sudo($this->username, 'cp', ['-sr', $LocalItem->path . '/.', $path]);
    } else {
      $this->sudo($this->username, 'rsync', ['-a', $LocalItem->path . '/', $path]);
    }
    
    if (! $copy_only) {
      $this->installedIndex->add($key, $LocalItem->version, $path, $LocalItem->files, $use_symlink);
      $this->say("Installed $key ({$LocalItem->version}) to $path");
    } else {
      $this->say("Copied $key ({$LocalItem->version}) to $path");
    }
  }

  function copy(string $key, string $path) {
    $Lock = $this->lock("username:{$this->username}");
    $this->_install($key, $path, true);
  }

  function install(string $key, string $path, $use_symlink=false) {
    $Lock = $this->lock("username:{$this->username}");

    if ($this->installedIndex->getIV($key)) {
      throw new Exception("Already installed: $key");
    }

    $this->_install($key, $path, false, $use_symlink);
  }

  function upgrade($keys=null) {
    $Lock = $this->lock("username:{$this->username}");

    if (is_string($keys)) {
      $keys = [$keys];
    }
    else if (is_null($keys)) {
      $keys = $this->installedIndex->search()->keys();
    }
    else if (is_array($keys)) {
      // do nothing
    } else {
      throw new Exception("invalid argument");
    }

    // grab all remote info into cache with one query
    $this->remoteIndex->search($keys);

    foreach ($keys as $key) {
      $Installed = $this->installedIndex->getIV($key);
      if (! $Installed) {
        throw new Exception("Not installed: $key");
      }

      $this->_install($key, null, false, $Installed->is_symlink);
    }
  }

  function uninstall(string $key) {
    $Lock = $this->lock("username:{$this->username}");

    $Installed = $this->installedIndex->getIV($key);
    if (! $Installed) {
      throw new Exception("Not installed: $key");
    }

    if ($files_to_remove = $Installed->files) {
      $this->remove_files($Installed->path, $files_to_remove);
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