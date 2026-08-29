<?php

namespace Ernestdefoe\OpenSearch\Job;

use Ernestdefoe\OpenSearch\Search\Discussion\DiscussionIndexer;
use Ernestdefoe\OpenSearch\Search\Post\PostIndexer;
use Ernestdefoe\OpenSearch\Search\User\UserIndexer;
use Flarum\Queue\AbstractJob;
use Illuminate\Contracts\Container\Container;

/**
 * Full rebuild of every index, run off the request thread. Dispatched by the
 * admin "Rebuild index" button.
 *
 * On the default `sync` queue this still runs inline, which is why the README
 * points at a real queue worker: rebuilding a large forum inside a web request
 * will hit the PHP time limit long before it finishes.
 */
class RebuildJob extends AbstractJob
{
    public int $tries = 1;

    public int $timeout = 3600;

    public function handle(Container $container): void
    {
        foreach ([DiscussionIndexer::class, UserIndexer::class, PostIndexer::class] as $indexer) {
            $container->make($indexer)->build();
        }
    }
}
