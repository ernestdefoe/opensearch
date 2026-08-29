<?php

namespace Ernestdefoe\OpenSearch\Search\Post;

use Ernestdefoe\OpenSearch\Search\AbstractIndexer;
use Flarum\Post\Post;
use Illuminate\Database\Eloquent\Builder;

/**
 * Indexes individual non-hidden comment posts into a `posts` index for
 * post-scoped search. Distinct from the discussion index, which folds post text
 * into the parent discussion's document.
 */
class PostIndexer extends AbstractIndexer
{
    protected const MAX_CONTENT = 100000;

    public static function index(): string
    {
        return 'posts';
    }

    protected function mapping(): array
    {
        return [
            'settings' => [
                'index' => [
                    'number_of_shards' => 1,
                    'number_of_replicas' => 0,
                ],
                'analysis' => [
                    'analyzer' => [
                        'flarum_text' => [
                            'type' => 'custom',
                            'tokenizer' => 'standard',
                            'filter' => ['lowercase', 'asciifolding'],
                        ],
                    ],
                ],
            ],
            'mappings' => [
                'properties' => [
                    'content' => ['type' => 'text', 'analyzer' => 'flarum_text'],
                    'discussion_id' => ['type' => 'long'],
                    'user_id' => ['type' => 'long'],
                    'number' => ['type' => 'integer'],
                    'created_at' => ['type' => 'date', 'format' => 'epoch_second'],
                ],
            ],
        ];
    }

    protected function baseQuery(): Builder
    {
        return Post::query()->where('type', 'comment')->whereNull('hidden_at');
    }

    /**
     * @param  Post[]  $models
     */
    protected function documentsFor(array $models): array
    {
        $docs = [];
        foreach ($models as $p) {
            if ($p->type !== 'comment' || $p->hidden_at !== null) {
                continue;
            }
            $docs[(int) $p->id] = [
                'content' => mb_substr(strip_tags((string) $p->content), 0, self::MAX_CONTENT),
                'discussion_id' => (int) $p->discussion_id,
                'user_id' => (int) ($p->user_id ?? 0),
                'number' => (int) ($p->number ?? 0),
                'created_at' => (int) ($p->created_at?->timestamp ?? 0),
            ];
        }

        return $docs;
    }
}
