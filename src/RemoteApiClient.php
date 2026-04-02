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
      'connect_timeout' => 5,
      'timeout'  => 30,
      'headers'  => [
        'Authorization' => "Bearer $apiKey",
        'Content-Type'  => 'application/json',
        'Accept'        => 'application/json',
      ],
    ]);
  }

  // List remote items, optionally filtered by prefix or exact key match
  function search(?string $match=null, bool $substring=false): ItemSet {
    $query = [];
    if ($match !== null && $match !== '') {
      $query['match'] = $match;
      if (!$substring) $query['exact'] = '1';
    }

    $data = $this->get('items', $query);
    return $this->itemSetFromRows($data['items']);
  }

  // Fetch the latest version for a specific set of keys, in one or more batch requests
  function fetchKeys(array $keys): ItemSet {
    if (empty($keys)) return new ItemSet();

    $ItemSet = new ItemSet();
    foreach (array_chunk(array_values($keys), 1000) as $chunk) {
      $data = $this->post('items/batch', ['keys' => $chunk]);
      foreach ($this->itemSetFromRows($data['items']) as $Item) {
        foreach ($Item as $IV) {
          $ItemSet->add($Item->key, $IV);
        }
      }
    }
    return $ItemSet;
  }

  private function itemSetFromRows(array $rows): ItemSet {
    $ItemSet = new ItemSet();
    foreach ($rows as $row) {
      $IV = new ItemVersion($row['version'], '', (int)$row['created_at']);
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
        return new ItemVersion($v['version'], '', (int)$v['created_at']);
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

  // Returns ['deleted' => string[], 'more' => bool]
  function cleanRemote(): array {
    $data = $this->post('clean', []);
    return [
      'deleted' => $data['deleted'] ?? [],
      'more'    => $data['more'] ?? false,
    ];
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

  // TODO: delete after migration
  function adminMigrate(string $world, array $items): array {
    return $this->post('admin/migrate', ['world' => $world, 'items' => $items]);
  }

  // TODO: delete after migration
  function adminWorlds(): array {
    return $this->get('admin/worlds');
  }

  private function get(string $path, array $query=[]): array {
    return $this->request('GET', $path, ['query' => $query]);
  }

  private function post(string $path, array $body): array {
    return $this->request('POST', $path, ['json' => $body]);
  }

  private function delete_(string $path): void {
    $this->request('DELETE', $path);
  }

  private function request(string $method, string $uri, array $options=[]): array {
    $waits = [1, 2, 4];
    $attempt = 0;

    while (true) {
      try {
        $resp = $this->http->request($method, $uri, $options);
        return json_decode((string)$resp->getBody(), true) ?? [];
      } catch (\GuzzleHttp\Exception\ConnectException $e) {
        if ($attempt >= count($waits)) throw $e;
        sleep($waits[$attempt++]);
      }
    }
  }
}
