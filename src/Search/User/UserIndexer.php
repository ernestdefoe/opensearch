<?php

namespace Ernestdefoe\OpenSearch\Search\User;

use Ernestdefoe\OpenSearch\Search\AbstractIndexer;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Indexes every user by username and display name for fast, typo-tolerant
 * lookup. Visibility is enforced at query time by the searcher, not here.
 */
class UserIndexer extends AbstractIndexer
{
    public static function index(): string
    {
        return 'users';
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
                        'flarum_name' => [
                            'type' => 'custom',
                            'tokenizer' => 'standard',
                            // asciifolding matters most here: it is what lets
                            // "jose" find "José".
                            'filter' => ['lowercase', 'asciifolding'],
                        ],
                    ],
                ],
            ],
            'mappings' => [
                'properties' => [
                    'username' => ['type' => 'text', 'analyzer' => 'flarum_name'],
                    'display_name' => ['type' => 'text', 'analyzer' => 'flarum_name'],
                    'joined_at' => ['type' => 'date', 'format' => 'epoch_second'],
                ],
            ],
        ];
    }

    protected function baseQuery(): Builder
    {
        return User::query();
    }

    /**
     * @param  User[]  $models
     */
    protected function documentsFor(array $models): array
    {
        $docs = [];
        foreach ($models as $u) {
            $docs[(int) $u->id] = [
                'username' => (string) $u->username,
                'display_name' => (string) ($u->display_name ?? $u->username),
                'joined_at' => (int) ($u->joined_at?->timestamp ?? 0),
            ];
        }

        return $docs;
    }
}
