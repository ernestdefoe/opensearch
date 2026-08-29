<?php

namespace Ernestdefoe\OpenSearch\Search\Discussion;

use Ernestdefoe\OpenSearch\Search\AbstractIndexer;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Illuminate\Database\Eloquent\Builder;

/**
 * Indexes each non-hidden discussion as one document: its title plus the
 * concatenated text of its visible comment posts, so a fulltext query matches
 * either the subject or anything said under it.
 */
class DiscussionIndexer extends AbstractIndexer
{
    protected const MAX_CONTENT = 100000;

    public static function index(): string
    {
        return 'discussions';
    }

    protected function mapping(): array
    {
        return [
            'settings' => [
                'index' => [
                    'number_of_shards' => 1,
                    // A forum index is small and read-mostly; a replica on a
                    // single-node cluster only leaves every index yellow.
                    'number_of_replicas' => 0,
                ],
                'analysis' => [
                    'analyzer' => [
                        'flarum_text' => [
                            'type' => 'custom',
                            'tokenizer' => 'standard',
                            // No stopword filter: on a forum, "the who" and
                            // "it" are real search terms.
                            'filter' => ['lowercase', 'asciifolding'],
                        ],
                    ],
                ],
            ],
            'mappings' => [
                'properties' => [
                    'title' => ['type' => 'text', 'analyzer' => 'flarum_text'],
                    'content' => ['type' => 'text', 'analyzer' => 'flarum_text'],
                    'comment_count' => ['type' => 'integer'],
                    'created_at' => ['type' => 'date', 'format' => 'epoch_second'],
                    'last_posted_at' => ['type' => 'date', 'format' => 'epoch_second'],
                    'is_private' => ['type' => 'boolean'],
                    'is_sticky' => ['type' => 'boolean'],
                    'user_id' => ['type' => 'long'],
                ],
            ],
        ];
    }

    protected function baseQuery(): Builder
    {
        return Discussion::query()->whereNull('hidden_at');
    }

    /**
     * @param  Discussion[]  $models
     */
    protected function documentsFor(array $models): array
    {
        $ids = array_values(array_filter(array_map(fn ($d) => (int) $d->id, $models)));
        $content = $this->contentFor($ids);

        $docs = [];
        foreach ($models as $d) {
            if ($d->hidden_at !== null) {
                continue;
            }
            $docs[(int) $d->id] = [
                'title' => (string) $d->title,
                'content' => $content[(int) $d->id] ?? '',
                'comment_count' => (int) ($d->comment_count ?? 0),
                'created_at' => (int) ($d->created_at?->timestamp ?? 0),
                'last_posted_at' => (int) ($d->last_posted_at?->timestamp ?? 0),
                'is_private' => (bool) $d->is_private,
                'is_sticky' => (bool) ($d->is_sticky ?? false),
                'user_id' => (int) ($d->user_id ?? 0),
            ];
        }

        return $docs;
    }

    /**
     * @param  int[]  $discussionIds
     * @return array<int, string>
     */
    protected function contentFor(array $discussionIds): array
    {
        $map = [];
        if (empty($discussionIds)) {
            return $map;
        }

        Post::query()
            ->where('type', 'comment')
            ->whereNull('hidden_at')
            ->whereIn('discussion_id', $discussionIds)
            ->orderBy('discussion_id')
            ->orderBy('number')
            ->select('discussion_id', 'content')
            ->chunk(2000, function ($posts) use (&$map) {
                foreach ($posts as $p) {
                    $did = (int) $p->discussion_id;
                    $current = $map[$did] ?? '';
                    if (mb_strlen($current) >= self::MAX_CONTENT) {
                        continue;
                    }
                    $map[$did] = $current.' '.strip_tags((string) $p->content);
                }
            });

        foreach ($map as $did => $text) {
            $map[$did] = mb_substr(trim($text), 0, self::MAX_CONTENT);
        }

        return $map;
    }
}
