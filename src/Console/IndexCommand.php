<?php

namespace Ernestdefoe\OpenSearch\Console;

use Ernestdefoe\OpenSearch\OpenSearchConnection;
use Ernestdefoe\OpenSearch\Search\Discussion\DiscussionIndexer;
use Ernestdefoe\OpenSearch\Search\Post\PostIndexer;
use Ernestdefoe\OpenSearch\Search\User\UserIndexer;
use Flarum\Console\AbstractCommand;
use Flarum\Search\IndexerInterface;
use Symfony\Component\Console\Input\InputOption;

class IndexCommand extends AbstractCommand
{
    public function __construct(
        protected OpenSearchConnection $opensearch,
        protected DiscussionIndexer $discussions,
        protected UserIndexer $users,
        protected PostIndexer $posts
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('opensearch:index')
            ->setDescription('Rebuild (or flush) the OpenSearch indexes: discussions, users and posts.')
            ->addOption('flush', null, InputOption::VALUE_NONE, 'Delete the indexes instead of rebuilding them.')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Limit to one index: discussions, users or posts.');
    }

    protected function fire(): int
    {
        if (! $this->opensearch->configured()) {
            $this->error('OpenSearch is not configured. Set the cluster URL under Admin → OpenSearch.');

            return 1;
        }

        // Fail here rather than after deleting an index, so a typo in --only
        // can never cost someone their search index.
        $ping = $this->opensearch->ping();
        if (! ($ping['ok'] ?? false)) {
            $this->error('Cannot reach the cluster: '.($ping['error'] ?? 'unknown error'));

            return 1;
        }

        /** @var array<string, IndexerInterface> $indexers */
        $indexers = [
            'discussions' => $this->discussions,
            'users' => $this->users,
            'posts' => $this->posts,
        ];

        $only = $this->input->getOption('only');
        if ($only !== null) {
            if (! isset($indexers[$only])) {
                $this->error('Unknown index "'.$only.'". Expected one of: '.implode(', ', array_keys($indexers)).'.');

                return 1;
            }
            $indexers = [$only => $indexers[$only]];
        }

        $flush = (bool) $this->input->getOption('flush');

        foreach ($indexers as $name => $indexer) {
            if ($flush) {
                $this->info("Flushing $name…");
                $indexer->flush();
            } else {
                $this->info("Rebuilding $name — this can take a while on large forums…");
                $indexer->build();
            }
        }

        $this->info('Done.'.($flush ? '' : ' Enable the OpenSearch driver per resource under Admin → OpenSearch.'));

        return 0;
    }
}
