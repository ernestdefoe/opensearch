<?php

namespace Ernestdefoe\OpenSearch\Search\User;

use Ernestdefoe\OpenSearch\Search\AbstractOpenSearchFulltextFilter;

class FulltextFilter extends AbstractOpenSearchFulltextFilter
{
    protected function index(): string
    {
        return 'users';
    }

    /**
     * Username above display name: someone typing a handle wants that account,
     * not everyone whose nickname happens to contain it.
     */
    protected function fields(): array
    {
        return ['username^2', 'display_name'];
    }

    protected function idColumn(): string
    {
        return 'users.id';
    }
}
