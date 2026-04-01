<?php
namespace Cacher2;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use \Exception;

class RemoteApiClient {
  private Client $http;

  function __construct(string $baseUrl, string $apiKey) {
    $this->http = new Client([
      'base_uri' => rtrim($baseUrl, '/') . '/',
      'timeout'  => 30,
      'headers'  => [
        'Authorization' => "Bearer $apiKey",
        'Content-Type'  => 'application/json',
        'Accept'        => 'application/json',
      ],
    ]);
  }

  // Returns ItemSet of all remote items, optionally filtered
  function search($key=null, bool $substring=false): ItemSet {
    $query = [];
    if (is_string($key) && $key !== '') {
      $query['match'] = $key;
      if (!$substring) $query['exact'] = '1';
    } else if (is_array($key) && count($key) > 0) {
      // For array of keys, fetch each one individually
      $ItemSet = new ItemSet();
      foreach ($key as $k) {
        $sub = $this->search($k, false);
        foreach ($sub as $Item) {
          foreach ($Item as $IV) {
            $ItemSet->add($Item->key, $IV);
          }
        }
      }
      return $ItemSet;
    }

    $data = $this->get('items', $query);
    $ItemSet = new ItemSet();
    foreach ($data['items'] as $row) {
      $IV = new ItemVersion($row['version'], '', (int)$row['created_at']);
      $IV->key = $row['key'];
      $ItemSet->add($row['key'], $IV);
    }
    return $ItemSet;
  }

  function versions(string $key): array {
    try {
      $data = $this->get('items/' . rawurlencode($key));
      return array_map(fn($v) => $v['version'], $data['versions']);
    } catch (ClientException $e) {
      if ($e->getResponse()->getStatusCode() === 404) return [];
      throw $e;
    }
  }

  function getIV(string $key, ?string $version=null): ?ItemVersion {
    try {
      $data = $this->get('items/' . rawurlencode($key));
    } catch (ClientException $e) {
      if ($e->getResponse()->getStatusCode() === 404) return null;
      throw $e;
    }

    if (empty($data['versions'])) return null;

    foreach ($data['versions'] as $v) {
      if (is_null($version) || $v['version'] === $version) {
        $IV = new ItemVersion($v['version'], '', (int)$v['created_at']);
        $IV->key = $key;
        return $IV;
      }
    }
    return null;
  }

  // Returns ['upload_url' => ..., 'object_key' => ...]
  function pushInit(string $key, string $version): array {
    return $this->post('push/init', ['key' => $key, 'version' => $version]);
  }

  function pushConfirm(string $key, string $version): void {
    $this->post('push/confirm', ['key' => $key, 'version' => $version]);
  }

  // Returns ['key' => ..., 'version' => ..., 'created_at' => ..., 'download_url' => ..., 'object_key' => ...]
  // Returns null if not found
  function pullInfo(string $key): ?array {
    try {
      return $this->post('pull', ['key' => $key]);
    } catch (ClientException $e) {
      if ($e->getResponse()->getStatusCode() === 404) return null;
      throw $e;
    }
  }

  function deleteItem(string $key, string $version): void {
    try {
      $this->delete_('items/' . rawurlencode($key) . '/' . rawurlencode($version));
    } catch (ClientException $e) {
      if ($e->getResponse()->getStatusCode() === 404) {
        throw new Exception("Item $key ($version) does not exist in remote cache");
      }
      throw $e;
    }
  }

  function cleanRemote(): array {
    $data = $this->post('clean', []);
    return $data['deleted'] ?? [];
  }

  function addUser(string $name, int $level, ?string $world=null): string {
    $body = ['name' => $name, 'level' => $level];
    if ($world !== null) $body['world'] = $world;
    $data = $this->post('users', $body);
    return $data['api_key'];
  }

  function delUser(string $name): void {
    try {
      $this->delete_('users/' . rawurlencode($name));
    } catch (ClientException $e) {
      if ($e->getResponse()->getStatusCode() === 404) {
        throw new Exception("User '$name' not found");
      }
      throw $e;
    }
  }

  function listUsers(): array {
    return $this->get('users')['users'];
  }

  // Used by migration tool only
  function adminMigrate(string $world, array $items): array {
    return $this->post('admin/migrate', ['world' => $world, 'items' => $items]);
  }

  function adminWorlds(): array {
    return $this->get('admin/worlds');
  }

  private function get(string $path, array $query=[]): array {
    $uri = $path;
    if ($query) $uri .= '?' . http_build_query($query);
    $resp = $this->http->get($uri);
    return json_decode((string)$resp->getBody(), true);
  }

  private function post(string $path, array $body): array {
    $resp = $this->http->post($path, ['json' => $body]);
    return json_decode((string)$resp->getBody(), true);
  }

  private function delete_(string $path): void {
    $this->http->delete($path);
  }
}
