<?php

namespace Ernestdefoe\OpenSearch\Tests\integration;

use Ernestdefoe\OpenSearch\OpenSearchConnection;

/**
 * The connection, with the cluster replaced by a script: every request is
 * recorded, a search answers with the hits it is given, and a cluster that is
 * "down" throws as a refused connection would.
 */
class FakeCluster extends OpenSearchConnection
{
    /** @var list<array{method: string, path: string, json: ?array, body: ?string}> */
    public array $requests = [];

    /** @var list<int> ids a search returns, in relevance order */
    public array $hits = [];

    public bool $down = false;

    public function configured(): bool
    {
        return true;
    }

    public function request(string $method, string $path, ?array $json = null, ?string $body = null, ?string $contentType = null): array
    {
        if ($this->down) {
            throw new \RuntimeException('Connection refused');
        }

        $this->requests[] = compact('method', 'path', 'json', 'body');

        if ($method === 'GET' && $path === '/') {
            return ['status' => 200, 'body' => ['version' => ['distribution' => 'opensearch', 'number' => '3.0.0']]];
        }

        if (str_ends_with($path, '/_search')) {
            return ['status' => 200, 'body' => ['hits' => ['hits' => array_map(fn ($id) => ['_id' => (string) $id], $this->hits)]]];
        }

        return ['status' => 200, 'body' => []];
    }

    /** @return list<string> "METHOD path" of every request so far */
    public function calls(): array
    {
        return array_map(fn ($r) => $r['method'].' '.$r['path'], $this->requests);
    }

    /**
     * Every action line sent through _bulk, as [action, index, id].
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public function bulkActions(): array
    {
        $out = [];
        foreach ($this->requests as $r) {
            if ($r['path'] !== '/_bulk') {
                continue;
            }
            foreach (explode("\n", trim((string) $r['body'])) as $line) {
                $decoded = json_decode($line, true);
                $action = array_key_first($decoded);
                if (in_array($action, ['index', 'delete'], true)) {
                    $out[] = [$action, $decoded[$action]['_index'], $decoded[$action]['_id']];
                }
            }
        }

        return $out;
    }
}
