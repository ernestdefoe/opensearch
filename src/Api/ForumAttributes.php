<?php

namespace Ernestdefoe\OpenSearch\Api;

use Ernestdefoe\OpenSearch\Search\OpenSearchDriver;
use Flarum\Api\Schema\Attribute;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;

/**
 * Tells the frontend which search tabs OpenSearch answers, so the search
 * modal can show a badge. Booleans only — the cluster URL and password
 * never leave the server.
 */
class ForumAttributes
{
    public function __construct(
        protected SettingsRepositoryInterface $settings
    ) {
    }

    /** @return list<Attribute> */
    public function __invoke(): array
    {
        return [
            Attribute::make('openSearchSearch')->get(fn () => array_keys(array_filter(
                [
                    'discussions' => Discussion::class,
                    'users' => User::class,
                    'posts' => Post::class,
                ],
                fn (string $model) => $this->settings->get("search_driver_$model") === OpenSearchDriver::name()
            ))),
        ];
    }
}
