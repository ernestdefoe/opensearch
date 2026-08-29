<?php

namespace Ernestdefoe\OpenSearch\Search\Discussion;

use Flarum\Discussion\Search\DiscussionSearcher;

/**
 * A distinct searcher class so this driver gets its own fulltext-filter mapping
 * (they are keyed by searcher class) without disturbing the database driver's.
 * All the query machinery — visibility via getQuery(), filters, sort,
 * pagination, SearchResults — is inherited from core's DiscussionSearcher.
 */
class OpenSearchDiscussionSearcher extends DiscussionSearcher
{
}
