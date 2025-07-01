<?php
namespace Cacher2;
use Carbon\Carbon;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Process\Process;
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
        'http' => [
          'connect_timeout' => 20,
        ],
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

  function push(string $path, string $key, ?string $version=null) {
    if (! $this->is_available('lz4')) {
      throw new Exception('lz4 is not available');
    }
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

    try {
      $lzdir = $this->mktempdir('lz4');
      $lzfile = $this->path_join($lzdir, '_extract.tar.lz4');
      $this->run("tar -C $path -cf - . | lz4 -q - $lzfile");
      $Manager = new \Aws\S3\Transfer($this->s3, $lzdir, $remote_path);
      $Manager->transfer();

    } finally {
      $this->filesystem()->remove($lzdir);
    }

    $this->remoteIndex->add($key, $version, $remote_path);
    $this->say("Pushed $key ($version)");
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
      $this->filesystem()->remove($local_path);
    }

    $StartedAt = Carbon::now();
    $this->sayf("Pulling %s (%s)...", $key, $version);
    mkdir($local_path, 0755, true);
    $Manager = new \Aws\S3\Transfer($this->s3, $Remote->path, $local_path);
    $Manager->transfer();

    $files = $this->list_files($local_path);

    $extract_filename = '_extract.tar.lz4';
    if (count($files) == 1 && $files[0] == $extract_filename) {
      try {
        $tmpdir = $this->mktempdir('lz4extract');
        $old = $this->path_join($local_path, $extract_filename);
        $new = $this->path_join($tmpdir, $extract_filename);
        rename($old, $new);
        $this->run("lz4 -d $new - | tar -C $local_path --no-same-owner --no-same-permissions -xf -");
        $files = $this->list_files($local_path);

      } finally {
        $this->filesystem()->remove($tmpdir);
      }
    }

    $this->localIndex->add($key, $version, $local_path, $files);
    $this->sayf("Pulled %s (%s) in %s", $key, $version, $StartedAt->shortAbsoluteDiffForHumans(2));
  }

  function deleteremote(string $key, ?string $version=null) {
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

  function deletelocal(string $key, ?string $version=null) {
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

    $this->filesystem()->remove($Item->path);
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

  function remoteinfo(?string $match=null, bool $exact = false): array {
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

  function localinfo(?string $match=null, bool $exact = false): array {
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
    if (!is_dir($basedir) || !$files) return;

    $basedir = realpath($basedir);
    $dirs = [];
    foreach ($files as &$file) {
      // all_dirnames() should get relative path, so it'll only return all dirs up to our basedir
      foreach ($this->all_dirnames($file) as $dir) {
        $dirs[] = realpath($this->path_join($basedir, $dir));
      }

      $file = $this->path_join($basedir, $file);
    }

    $this->longsudo($this->username, 'rm -f', $files);

    $dirs = array_unique($dirs);
    while (true) {
      $dirs_to_remove = array_filter($dirs, fn($dir) => $this->dir_is_empty($dir));
      if (! $dirs_to_remove) break;

      $this->longsudo($this->username, 'rmdir', $dirs_to_remove);
      $dirs = array_diff($dirs, $dirs_to_remove);
    }
  }

  private function all_dirnames(string $filename) {
    $names = [];
    $i = 1;
    while (true) {
      $dir = dirname($filename, $i++);
      if ($dir != '.') $names[] = $dir;
      else break;
    }
    return $names;
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
    static $Factory;

    if (! $Factory) {
      $Factory = new LockFactory(new FlockStore($this->tmp_dir()));
    }
    
    $Lock = $Factory->createLock($name);
    $Lock->acquire(true);
    return $Lock;
  }

  function tmp_dir() {
    $tmp_dir = $this->path_join($this->const('CACHER_HOME'), '.tmp');
    if (! is_dir($tmp_dir)) mkdir($tmp_dir, 0700);
    return $tmp_dir;
  }

  function mktempdir(string $prefix='') {
    $dir = $this->path_join($this->tmp_dir(), uniqid($prefix));
    mkdir($dir, 0700);
    return $dir;
  }

  function filesystem() {
    static $fs;
    if (! $fs) {
      $fs = new Filesystem();
    }
    return $fs;
  }

  function longsudo(string $username, string $command, array $args=[]) {
    try {
      $tmpfile = tempnam($this->const('CACHER_HOME'), '.rmlist');
      chmod($tmpfile, 0644);
      file_put_contents($tmpfile, implode("\0", $args) . "\0");
      $this->sayf_debug('[longsudo %s]: %s %s ... (%d total)', 
        $username, $command, implode(' ', array_slice($args, 0, 10)), count($args));
      return $this->sudo($username, "xargs -0 $command < $tmpfile");
    }
    finally {
      if ($tmpfile) unlink($tmpfile);
    }
  }

  function sudo(string $username, string $command, array $args=[]) {
    $full_command = implode(' ', [$command, ...array_map('escapeshellarg', $args)]);

    if (!defined('CACHER_IS_DEVELOPMENT')) {
      if (posix_geteuid() != 0) {
        throw new Exception("Unable to sudo to $username: we are not root");
      }
      if (! posix_getpwnam($username)) {
        throw new Exception("Unable to sudo to $username: user does not exist");
      }
      
      $this->sayf_debug('[sudo %s]: %s', $username, $full_command);
      return $this->run('sudo', ['-Hn', '-u', $username, 'bash', '-c', $full_command]);
    }
    
    $this->sayf_debug('[fakesudo %s]: %s', $username, $full_command);
    return $this->run($full_command);
  }

  function run(string $command, array $args=[]): string {
    $full_command = implode(' ', [$command, ...array_map('escapeshellarg', $args)]);
    $this->say_debug('[run]:', $full_command);
    
    $Process = Process::fromShellCommandline($full_command);
    $Process->mustRun();

    $output = $Process->getOutput() . "\n" . $Process->getErrorOutput();
    $output = trim($output);
    return $output;
  }

  function is_available(string $program): bool {
    try {
      $this->run('which', [$program], true);
    } catch (Exception $e) {
      return false;
    }
    
    return true;
  }

  function say_debug(string ...$messages) {
    if (!defined('CACHER_IS_DEVELOPMENT')) return;
    return $this->say(...$messages);
  }

  function sayf_debug(string $str, string ...$args) {
    return $this->say_debug(sprintf($str, ...$args));
  }

  function sayf(string $str, string ...$args) {
    return $this->say(sprintf($str, ...$args));
  }

  function say(string ...$messages) {
    if (php_sapi_name() != 'cli') return;
    echo join(' ', $messages), "\n";
  }

}