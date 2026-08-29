<?php

namespace Ernestdefoe\OpenSearch\Api\Controller;

use Ernestdefoe\OpenSearch\Job\RebuildJob;
use Ernestdefoe\OpenSearch\OpenSearchConnection;
use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Queue\Queue;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class RebuildController implements RequestHandlerInterface
{
    public function __construct(
        protected OpenSearchConnection $opensearch,
        protected Queue $queue
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        if (! $this->opensearch->configured()) {
            return new JsonResponse(['error' => 'not_configured'], 422);
        }

        // A rebuild deletes each index before refilling it, so starting one
        // against an unreachable cluster would take search down and leave it
        // down. Check first.
        $ping = $this->opensearch->ping();
        if (! ($ping['ok'] ?? false)) {
            return new JsonResponse(['error' => $ping['error'] ?? 'unreachable'], 422);
        }

        $this->queue->push(new RebuildJob());

        return new JsonResponse(['status' => 'queued'], 202);
    }
}
