<?php

namespace Ernestdefoe\OpenSearch\Search\Discussion;

use Ernestdefoe\OpenSearch\OpenSearchConnection;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Search\IndexerInterface;

/**
 * A post's text lives inside its parent discussion's document, so creating,
 * editing or deleting a post has to re-index the discussion it belongs to.
 * This is the discussion-search half of post indexing; Search\Post\PostIndexer
 * owns the separate `posts` index.
 *
 * build() and flush() are deliberately empty — the discussions index belongs to
 * DiscussionIndexer, and rebuilding it from here would wipe it a second time
 * mid-build.
 */
class PostReindexer implements IndexerInterface
{
    public function __construct(
        protected OpenSearchConnection $opensearch,
        protected DiscussionIndexer $discussions
    ) {
    }

    public static function index(): string
    {
        return 'discussions';
    }

    /**
     * @param  Post[]  $models
     */
    public function save(array $models): void
    {
        $this->reindexParents($models);
    }

    /**
     * @param  Post[]  $models
     */
    public function delete(array $models): void
    {
        $this->reindexParents($models);
    }

    public function build(): void
    {
    }

    public function flush(): void
    {
    }

    /**
     * @param  Post[]  $posts
     */
    protected function reindexParents(array $posts): void
    {
        if (! $this->opensearch->configured() || empty($posts)) {
            return;
        }

        $ids = array_values(array_unique(array_filter(array_map(
            fn ($p) => (int) $p->discussion_id,
            $posts
        ))));
        if (empty($ids)) {
            return;
        }

        $discussions = Discussion::query()->whereIn('id', $ids)->get()->all();
        if (! empty($discussions)) {
            $this->discussions->save($discussions);
        }
    }
}
