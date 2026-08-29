<?php

namespace Ernestdefoe\OpenSearch\Search\Post;

use Ernestdefoe\OpenSearch\Search\AbstractOpenSearchFulltextFilter;

class FulltextFilter extends AbstractOpenSearchFulltextFilter
{
    protected function index(): string
    {
        return 'posts';
    }

    protected function fields(): array
    {
        return ['content'];
    }

    protected function idColumn(): string
    {
        return 'posts.id';
    }
}
