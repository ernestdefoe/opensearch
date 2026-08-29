<?php

namespace Ernestdefoe\OpenSearch\Search\User;

use Flarum\User\Search\UserSearcher;

/**
 * A distinct searcher class so this driver owns its own fulltext-filter
 * mapping; see OpenSearchDiscussionSearcher for the reasoning.
 */
class OpenSearchUserSearcher extends UserSearcher
{
}
