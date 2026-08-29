<?php

namespace Ernestdefoe\OpenSearch\Search\Post;

use Flarum\Post\Filter\PostSearcher;

/**
 * A distinct searcher class so this driver owns its own fulltext-filter
 * mapping; see OpenSearchDiscussionSearcher for the reasoning.
 */
class OpenSearchPostSearcher extends PostSearcher
{
}
