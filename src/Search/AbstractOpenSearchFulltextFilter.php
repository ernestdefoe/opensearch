<?php

namespace Ernestdefoe\OpenSearch\Search;

use Ernestdefoe\OpenSearch\OpenSearchConnection;
use Flarum\Search\AbstractFulltextFilter;
use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\SearchState;

/**
 * Runs the fulltext half of a search against the cluster: ask for matching IDs
 * in relevance order, constrain the Eloquent query to them, and keep that order
 * as the default sort.
 *
 * Permissions never depend on this. The searcher's getQuery() has already
 * applied whereVisibleTo, so the cluster only narrows and orders candidates
 * that the actor may already see. When the cluster is not configured, has no
 * index yet or does not answer, core's database fulltext filter runs instead
 * for that request, so an outage means slower results, never none.
 */
abstract class AbstractOpenSearchFulltextFilter extends AbstractFulltextFilter
{
    /**
     * Ceiling on IDs pulled back from the cluster. Every one becomes a bound
     * parameter in a WHERE IN plus a CASE arm, so this trades result depth
     * against query size; 250 is far more than a human pages through.
     */
    protected const MAX_HITS = 250;

    public function __construct(
        protected OpenSearchConnection $opensearch
    ) {
    }

    abstract protected function index(): string;

    /**
     * Core's database fulltext filter for this resource, used when the
     * cluster cannot answer.
     *
     * @return class-string<AbstractFulltextFilter>
     */
    abstract protected function databaseFilter(): string;

    /**
     * Fields to match, in `field^boost` form.
     *
     * @return string[]
     */
    abstract protected function fields(): array;

    abstract protected function idColumn(): string;

    public function search(SearchState $state, string $value): void
    {
        /** @var DatabaseSearchState $state */
        $query = $state->getQuery();

        $ids = $this->matchingIds($value);

        if ($ids === null) {
            resolve($this->databaseFilter())->search($state, $value);

            return;
        }

        if (empty($ids)) {
            $query->whereRaw('0 = 1');

            return;
        }

        $query->whereIn($this->idColumn(), $ids);

        // Preserve relevance order as the default sort — applied only when the
        // request asked for no explicit sort. Portable CASE ordering, so this
        // works on MySQL, PostgreSQL and SQLite alike.
        // 🚨 The column goes through the grammar: raw SQL skips the table
        // prefix, and a bare `discussions.id` broke every prefixed forum.
        $column = $this->idColumn();
        $state->setDefaultSort(function ($q) use ($column, $ids) {
            $sql = 'CASE '.$q->getQuery()->getGrammar()->wrap($column);
            foreach (array_keys($ids) as $position) {
                $sql .= ' WHEN ? THEN '.(int) $position;
            }
            $q->orderByRaw($sql.' END', $ids);
        });
    }

    /**
     * @return int[]|null IDs in relevance order, or null when the cluster could not answer
     */
    protected function matchingIds(string $value): ?array
    {
        if (! $this->opensearch->configured()) {
            return null;
        }

        try {
            $res = $this->opensearch->request(
                'POST',
                '/'.$this->opensearch->indexName($this->index()).'/_search',
                $this->query($value)
            );
        } catch (\Throwable $e) {
            $this->opensearch->unreachable($this->index().' search', $e->getMessage());

            return null;
        }

        // An index that was never built is the state of every fresh install
        // before the first rebuild: not worth a log line, but the database
        // can still answer.
        if ($res['status'] === 404) {
            return null;
        }

        if ($res['status'] >= 300) {
            $this->opensearch->unreachable($this->index().' search', $res['body']['error']['reason'] ?? 'HTTP '.$res['status']);

            return null;
        }

        return array_values(array_filter(array_map(
            fn ($hit) => (int) ($hit['_id'] ?? 0),
            $res['body']['hits']['hits'] ?? []
        )));
    }

    /**
     * The search body.
     *
     * `best_fields` scores a document by its single best-matching field rather
     * than summing them, which is what you want when the fields are alternative
     * phrasings of the same thing (a title and its body) instead of independent
     * facets. `and` requires every term, matching what people expect from a
     * forum search box: adding a word narrows the results.
     *
     * @return array<string, mixed>
     */
    protected function query(string $value): array
    {
        return [
            'size' => static::MAX_HITS,
            // Only IDs are used — the rows are read from the database — so
            // pulling _source back would be wasted bandwidth on every search.
            '_source' => false,
            'query' => [
                'multi_match' => [
                    'query' => $value,
                    'fields' => $this->fields(),
                    'type' => 'best_fields',
                    'operator' => 'and',
                    // One typo's worth of tolerance on longer words, which is
                    // what AUTO gives: exact for 1-2 characters, one edit up to
                    // five, two beyond. Short words stay exact so "cat" never
                    // quietly matches "car".
                    'fuzziness' => 'AUTO',
                ],
            ],
        ];
    }
}
