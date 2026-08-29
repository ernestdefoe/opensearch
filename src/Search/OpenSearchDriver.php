<?php

namespace Ernestdefoe\OpenSearch\Search;

use Flarum\Search\AbstractDriver;

/**
 * The OpenSearch driver. Selected per-resource via the
 * `search_driver_<ModelClass>` setting; core's SearchManager routes only
 * fulltext (text-query) searches here — plain filtering and browsing stay on
 * the database driver — and each searcher's getQuery() still applies
 * whereVisibleTo, so the cluster never decides permissions.
 */
class OpenSearchDriver extends AbstractDriver
{
    public static function name(): string
    {
        return 'opensearch';
    }
}
