<?php

declare(strict_types=1);

namespace RenzoFranceschini\GuardCorePsr15;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RenzoFranceschini\GuardCore\Engine\GuardEngine;

/**
 * GET /_guard/status handler: serves the engine's initialization status
 * (per-component enabled/ok/error from initialize()), mirroring
 * fastapi-guard's add_status_route. Register it on any PSR-15 router with
 * your engine and stream factories:
 *
 *   $route->get('/_guard/status', new GuardStatusRequestHandler($engine, $responseFactory, $streamFactory));
 */
final class GuardStatusRequestHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly GuardEngine $engine,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $payload = json_encode($this->engine->initializationStatus(), JSON_THROW_ON_ERROR);
        $stream = $this->streamFactory->createStream($payload);

        return $this->responseFactory
            ->createResponse(200)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($stream);
    }
}
