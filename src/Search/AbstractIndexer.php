<?php

namespace Ernestdefoe\OpenSearch\Search;

use Ernestdefoe\OpenSearch\OpenSearchConnection;
use Flarum\Search\IndexerInterface;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Eloquent\Builder;
use Psr\Log\LoggerInterface;

/**
 * Shared index plumbing for the concrete resource indexers. Subclasses declare
 * their index name, mapping, base query for a full build, and how to turn a
 * batch of models into documents.
 *
 * Everything no-ops cleanly when the cluster isn't configured, so enabling the
 * extension before entering connection details never errors on a save — a
 * forum has to stay usable while search is half set up.
 */
abstract class AbstractIndexer implements IndexerInterface
{
    /**
     * Rows pulled from the database per chunk during a full build. Kept well
     * below the bulk-body ceiling: 500 discussions with their concatenated post
     * text can run to tens of megabytes, and OpenSearch rejects an oversized
     * _bulk body wholesale rather than partially.
     */
    protected const BUILD_CHUNK = 200;

    /** Documents per _bulk request. */
    protected const BULK_SIZE = 100;

    /**
     * How long a confirmed index is trusted before it is checked again.
     */
    protected const EXISTS_TTL = 600;

    public function __construct(
        protected OpenSearchConnection $opensearch,
        protected LoggerInterface $log,
        protected Cache $cache
    ) {
    }

    /** @return array<string, mixed> The index body: settings + mappings. */
    abstract protected function mapping(): array;

    /**
     * @param  object[]  $models
     * @return array<int|string, array<string, mixed>> id => document
     */
    abstract protected function documentsFor(array $models): array;

    abstract protected function baseQuery(): Builder;

    public function save(array $models): void
    {
        if (! $this->opensearch->configured() || empty($models)) {
            return;
        }

        $this->ensureIndex();
        $this->bulkIndex($this->documentsFor($models));
    }

    public function delete(array $models): void
    {
        if (! $this->opensearch->configured() || empty($models)) {
            return;
        }

        $ids = array_values(array_filter(array_map(fn ($m) => (int) $m->id, $models)));
        if (empty($ids)) {
            return;
        }

        $lines = '';
        foreach ($ids as $id) {
            $lines .= json_encode(['delete' => ['_index' => $this->indexName(), '_id' => (string) $id]])."\n";
        }

        try {
            // Deleting a document that was never indexed comes back as
            // "not_found" inside a 200, which is the right outcome here —
            // nothing to do — so bulk errors are not worth logging on deletes.
            $this->opensearch->request('POST', '/_bulk', null, $lines, 'application/x-ndjson');
        } catch (\Throwable $e) {
            $this->log->warning('[opensearch] '.static::index().' delete failed: '.$e->getMessage());
        }
    }

    public function build(): void
    {
        if (! $this->opensearch->configured()) {
            return;
        }

        $this->flush();
        $this->createIndex();

        $this->baseQuery()
            ->orderBy('id')
            ->chunk(self::BUILD_CHUNK, function ($models) {
                $this->bulkIndex($this->documentsFor($models->all()));
            });

        // Without this a rebuild "finishes" and an immediate search still sees
        // the old contents: OpenSearch refreshes on its own schedule (1s by
        // default, and not at all for an index nobody is searching).
        $this->refresh();
    }

    public function flush(): void
    {
        if (! $this->opensearch->configured()) {
            return;
        }

        $this->cache->forget($this->existsKey());

        try {
            $this->opensearch->request('DELETE', '/'.$this->indexName());
        } catch (\Throwable $e) {
            // Index didn't exist, or the cluster is unreachable — nothing to flush.
        }
    }

    protected function indexName(): string
    {
        return $this->opensearch->indexName(static::index());
    }

    /** @param array<int|string, array<string, mixed>> $docs */
    protected function bulkIndex(array $docs): void
    {
        if (empty($docs)) {
            return;
        }

        foreach (array_chunk($docs, self::BULK_SIZE, true) as $chunk) {
            $lines = '';
            foreach ($chunk as $id => $doc) {
                $lines .= json_encode(['index' => ['_index' => $this->indexName(), '_id' => (string) $id]])."\n";
                $lines .= json_encode($doc)."\n";
            }

            try {
                $res = $this->opensearch->request('POST', '/_bulk', null, $lines, 'application/x-ndjson');

                // _bulk answers 200 even when every document in it failed, so
                // the status proves nothing on its own — the `errors` flag is
                // the only thing that says whether the content actually landed.
                if ($res['status'] >= 300 || ! empty($res['body']['errors'])) {
                    $this->log->warning('[opensearch] '.static::index().' bulk index reported errors: '.$this->firstBulkError($res));
                }
            } catch (\Throwable $e) {
                $this->log->warning('[opensearch] '.static::index().' bulk index failed: '.$e->getMessage());
            }
        }
    }

    /** @param array{status: int, body: array<string, mixed>} $res */
    protected function firstBulkError(array $res): string
    {
        foreach ($res['body']['items'] ?? [] as $item) {
            $error = $item['index']['error'] ?? $item['create']['error'] ?? null;
            if ($error) {
                return (string) ($error['reason'] ?? $error['type'] ?? 'unknown');
            }
        }

        return (string) ($res['body']['error']['reason'] ?? 'HTTP '.$res['status']);
    }

    protected function refresh(): void
    {
        try {
            $this->opensearch->request('POST', '/'.$this->indexName().'/_refresh');
        } catch (\Throwable $e) {
            // Best effort — the index refreshes on its own schedule anyway.
        }
    }

    /**
     * 🚨 Remembered in the cache, not asked on every save. Every post, reply,
     * edit and profile change is indexed, and on a host with the sync queue
     * that runs inside the visitor's request — so a HEAD per save doubled the
     * round trips to the cluster on every write, for an answer that almost
     * never changes. flush() forgets it, so a rebuild still re-creates.
     */
    protected function ensureIndex(): void
    {
        if ($this->cache->get($this->existsKey())) {
            return;
        }

        try {
            $res = $this->opensearch->request('HEAD', '/'.$this->indexName());
            if ($res['status'] === 404) {
                $this->createIndex();
            } elseif ($res['status'] < 300) {
                $this->cache->put($this->existsKey(), true, self::EXISTS_TTL);
            }
        } catch (\Throwable $e) {
            // Unreachable cluster — the indexing call logs its own failure.
        }
    }

    protected function existsKey(): string
    {
        return 'ernestdefoe-opensearch.exists.'.$this->indexName();
    }

    protected function createIndex(): void
    {
        try {
            $res = $this->opensearch->request('PUT', '/'.$this->indexName(), $this->mapping());

            if ($res['status'] < 300 || ($res['body']['error']['type'] ?? '') === 'resource_already_exists_exception') {
                $this->cache->put($this->existsKey(), true, self::EXISTS_TTL);
            }

            // resource_already_exists_exception means another request won the
            // race, which is a success as far as this is concerned.
            if ($res['status'] >= 300
                && ($res['body']['error']['type'] ?? '') !== 'resource_already_exists_exception') {
                $this->log->warning('[opensearch] could not create index '.$this->indexName().': '
                    .($res['body']['error']['reason'] ?? 'HTTP '.$res['status']));
            }
        } catch (\Throwable $e) {
            $this->log->warning('[opensearch] could not create index '.$this->indexName().': '.$e->getMessage());
        }
    }
}
