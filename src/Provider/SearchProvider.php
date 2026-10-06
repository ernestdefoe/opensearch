<?php

namespace Ernestdefoe\OpenSearch\Provider;

use Ernestdefoe\OpenSearch\OpenSearchConnection;
use Ernestdefoe\OpenSearch\Search\Discussion\OpenSearchDiscussionSearcher;
use Ernestdefoe\OpenSearch\Search\Post\OpenSearchPostSearcher;
use Ernestdefoe\OpenSearch\Search\User\OpenSearchUserSearcher;
use Flarum\Discussion\Search\DiscussionSearcher;
use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Post\Filter\PostSearcher;
use Flarum\User\Search\UserSearcher;

/**
 * Each searcher here is a distinct class, so it owns its own fulltext mapping
 * without clobbering the database driver's. The cost of that is that filters
 * and mutators registered against core's searchers — by core itself, and by
 * extensions such as flarum/tags for `tag:` refinement — are keyed to the core
 * class and would never reach these. Mirroring them across at resolution time
 * means an OpenSearch fulltext search honours exactly the same refinements as
 * a database one.
 *
 * This affects result *refinement* only. Permissions never depend on it: every
 * searcher's getQuery() applies whereVisibleTo regardless of what is mirrored.
 */
class SearchProvider extends AbstractServiceProvider
{
    /**
     * This driver's searcher => the core searcher whose filters/mutators it mirrors.
     */
    protected const MIRROR = [
        OpenSearchDiscussionSearcher::class => DiscussionSearcher::class,
        OpenSearchUserSearcher::class => UserSearcher::class,
        OpenSearchPostSearcher::class => PostSearcher::class,
    ];

    public function register(): void
    {
        $this->container->singleton(OpenSearchConnection::class);
    }

    /**
     * In boot(), not register(): extensions that load after this one add their
     * filters later, and a mirror taken at register time missed them.
     */
    public function boot(): void
    {
        $this->container->extend('flarum.search.filters', function (array $filters) {
            foreach (self::MIRROR as $mine => $parent) {
                $filters[$mine] = array_values(array_unique(array_merge(
                    $filters[$mine] ?? [],
                    $filters[$parent] ?? []
                )));
            }

            return $filters;
        });

        $this->container->extend('flarum.search.mutators', function (array $mutators) {
            foreach (self::MIRROR as $mine => $parent) {
                $mutators[$mine] = array_merge(
                    $mutators[$mine] ?? [],
                    $mutators[$parent] ?? []
                );
            }

            return $mutators;
        });
    }
}
