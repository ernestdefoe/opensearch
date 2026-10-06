<?php

namespace Ernestdefoe\OpenSearch\Search;

use Ernestdefoe\OpenSearch\OpenSearchConnection;
use Flarum\Search\AbstractFulltextFilter;
use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\SearchState;
use Psr\Log\LoggerInterface;

/**
 * Runs the fulltext half of a search against the cluster: ask for matching IDs
 * in relevance order, constrain the Eloquent query to them, and keep that order
 * as the default sort.
 *
 * Permissions never depend on this. The searcher's getQuery() has already
 * applied whereVisibleTo, so the cluster only narrows and orders candidates
 * that the actor may already see. On any failure the search degrades to "no
 * matches" rather than throwing, so a blip in the cluster never 500s a search
 * box.
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
        protected OpenSearchConnection $opensearch,
        protected LoggerInterface $log
    ) {
    }

    abstract protected function index(): string;

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
     * @return int[] IDs in relevance order (empty on miss or error)
     */
    protected function matchingIds(string $value): array
    {
        if (! $this->opensearch->configured()) {
            return [];
        }

        try {
            $res = $this->opensearch->request(
                'POST',
                '/'.$this->opensearch->indexName($this->index()).'/_search',
                $this->query($value)
            );

            // An index that was never built is not an error worth shouting
            // about — it is the state of every fresh install before the first
            // rebuild. Report no matches and let the admin notice the empty
            // results, rather than filling the log on every keystroke.
            if ($res['status'] === 404) {
                return [];
            }

            if ($res['status'] >= 300) {
                $this->log->error('[opensearch] '.$this->index().' search failed: '
                    .($res['body']['error']['reason'] ?? 'HTTP '.$res['status']));

                return [];
            }

            return array_values(array_filter(array_map(
                fn ($hit) => (int) ($hit['_id'] ?? 0),
                $res['body']['hits']['hits'] ?? []
            )));
        } catch (\Throwable $e) {
            $this->log->error('[opensearch] '.$this->index().' search failed: '.$e->getMessage());

            return [];
        }
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
