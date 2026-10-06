<?php

use Ernestdefoe\OpenSearch\Api\Controller\RebuildController;
use Ernestdefoe\OpenSearch\Api\Controller\TestConnectionController;
use Ernestdefoe\OpenSearch\Console\IndexCommand;
use Ernestdefoe\OpenSearch\Provider\SearchProvider;
use Ernestdefoe\OpenSearch\Search\Discussion\DiscussionIndexer;
use Ernestdefoe\OpenSearch\Search\Discussion\FulltextFilter as DiscussionFulltextFilter;
use Ernestdefoe\OpenSearch\Search\Discussion\OpenSearchDiscussionSearcher;
use Ernestdefoe\OpenSearch\Search\Discussion\PostReindexer;
use Ernestdefoe\OpenSearch\Search\OpenSearchDriver;
use Ernestdefoe\OpenSearch\Search\Post\FulltextFilter as PostFulltextFilter;
use Ernestdefoe\OpenSearch\Search\Post\OpenSearchPostSearcher;
use Ernestdefoe\OpenSearch\Search\Post\PostIndexer;
use Ernestdefoe\OpenSearch\Search\User\FulltextFilter as UserFulltextFilter;
use Ernestdefoe\OpenSearch\Search\User\OpenSearchUserSearcher;
use Ernestdefoe\OpenSearch\Search\User\UserIndexer;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Schema\Attribute;
use Flarum\Discussion\Discussion;
use Flarum\Extend;
use Flarum\Post\CommentPost;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;

return [
    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/less/forum.less'),

    /*
     * Tells the frontend which search tabs OpenSearch answers, so the search
     * modal can show a badge. Booleans only — the cluster URL and password
     * never leave the server.
     */
    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Attribute::make('openSearchSearch')->get(function () {
                $settings = resolve(SettingsRepositoryInterface::class);

                $resources = [
                    'discussions' => Discussion::class,
                    'users' => User::class,
                    'posts' => Post::class,
                ];

                return array_keys(array_filter(
                    $resources,
                    fn (string $model) => $settings->get("search_driver_$model") === OpenSearchDriver::name()
                ));
            }),
        ]),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\SearchDriver(OpenSearchDriver::class))
        ->addSearcher(Discussion::class, OpenSearchDiscussionSearcher::class)
        ->setFulltext(OpenSearchDiscussionSearcher::class, DiscussionFulltextFilter::class)
        ->addSearcher(User::class, OpenSearchUserSearcher::class)
        ->setFulltext(OpenSearchUserSearcher::class, UserFulltextFilter::class)
        ->addSearcher(Post::class, OpenSearchPostSearcher::class)
        ->setFulltext(OpenSearchPostSearcher::class, PostFulltextFilter::class),

    (new Extend\SearchIndex())
        ->indexer(Discussion::class, DiscussionIndexer::class)
        // Both post indexers are registered: PostReindexer refreshes the parent
        // discussion's document, PostIndexer maintains the separate posts index.
        // 🚨 Under CommentPost as well as Post: core observes the exact class
        // it is given, and a reply is saved as a CommentPost, whose model
        // events (and its `hidden` event, declared only on CommentPost) a Post
        // observer never hears. Under Post alone, no new reply, edit, hide or
        // delete ever reached the index until the next full rebuild.
        ->indexer(Post::class, PostReindexer::class)
        ->indexer(Post::class, PostIndexer::class)
        ->indexer(CommentPost::class, PostReindexer::class)
        ->indexer(CommentPost::class, PostIndexer::class)
        ->indexer(User::class, UserIndexer::class),

    new Extend\ServiceProvider(SearchProvider::class),

    (new Extend\Console())
        ->command(IndexCommand::class),

    (new Extend\Routes('api'))
        ->get('/opensearch/status', 'ernestdefoe-opensearch.status', TestConnectionController::class)
        ->post('/opensearch/rebuild', 'ernestdefoe-opensearch.rebuild', RebuildController::class),
];
