<?php

namespace Ernestdefoe\OpenSearch\Api\Controller;

use Ernestdefoe\OpenSearch\OpenSearchConnection;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class TestConnectionController implements RequestHandlerInterface
{
    public function __construct(
        protected OpenSearchConnection $opensearch
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        return new JsonResponse($this->opensearch->ping());
    }
}
