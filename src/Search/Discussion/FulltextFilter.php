<?php

namespace Ernestdefoe\OpenSearch\Search\Discussion;

use Ernestdefoe\OpenSearch\Search\AbstractOpenSearchFulltextFilter;

class FulltextFilter extends AbstractOpenSearchFulltextFilter
{
    protected function databaseFilter(): string
    {
        return \Flarum\Discussion\Search\FulltextFilter::class;
    }

    protected function index(): string
    {
        return 'discussions';
    }

    /**
     * The title is weighted well above the body: a thread called "Redis caching"
     * is a better answer for `redis caching` than one that mentions the phrase
     * once in a reply.
     */
    protected function fields(): array
    {
        return ['title^3', 'content'];
    }

    protected function idColumn(): string
    {
        return 'discussions.id';
    }
}
