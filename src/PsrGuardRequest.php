<?php

declare(strict_types=1);

namespace RenzoFranceschini\GuardCorePsr15;

use Psr\Http\Message\ServerRequestInterface;
use RenzoFranceschini\GuardCore\Request\GuardRequest;
use RenzoFranceschini\GuardCore\Request\HeaderBag;
use RenzoFranceschini\GuardCore\Request\RequestState;

final class PsrGuardRequest implements GuardRequest
{
    public const MAX_BODY_BYTES = 262144;

    private const READ_CHUNK_BYTES = 65536;

    private RequestState $state;

    private ?string $cachedBody = null;

    private bool $bodyRead = false;

    private bool $bodyTruncated = false;

    private string $unrewindableConsumed = '';

    public function __construct(private readonly ServerRequestInterface $request)
    {
        $this->state = new RequestState();
    }

    public function urlPath(): string
    {
        return $this->request->getUri()->getPath();
    }

    public function urlScheme(): string
    {
        return $this->request->getUri()->getScheme();
    }

    public function urlFull(): string
    {
        $uri = $this->request->getUri();
        $url = $this->urlScheme() . '://' . $uri->getHost() . $uri->getPath();
        $query = $uri->getQuery();

        return $query !== '' ? $url . '?' . $query : $url;
    }

    public function urlReplaceScheme(string $scheme): string
    {
        $full = $this->urlFull();

        return $scheme . '://' . substr($full, strlen($this->urlScheme()) + 3);
    }

    public function method(): string
    {
        return strtoupper($this->request->getMethod());
    }

    public function clientHost(): ?string
    {
        $remoteAddr = $this->request->getServerParams()['REMOTE_ADDR'] ?? null;
        if (!is_string($remoteAddr) || $remoteAddr === '') {
            return null;
        }

        return $remoteAddr;
    }

    public function headers(): HeaderBag
    {
        $headers = [];
        foreach ($this->request->getHeaders() as $name => $values) {
            $headers[$name] = $this->request->getHeaderLine($name);
        }

        return new HeaderBag($headers);
    }

    /** @return array<string, string|list<string>> */
    public function queryParams(): array
    {
        return $this->request->getQueryParams();
    }

    public function body(): string
    {
        if ($this->bodyRead) {
            return $this->cachedBody ?? '';
        }

        $buffer = '';
        $stream = $this->request->getBody();
        $seekable = $stream->isSeekable();
        $start = $seekable ? $stream->tell() : 0;
        while (strlen($buffer) < self::MAX_BODY_BYTES) {
            $chunk = $stream->read(min(self::READ_CHUNK_BYTES, self::MAX_BODY_BYTES - strlen($buffer)));
            if ($chunk === '') {
                break;
            }
            $buffer .= $chunk;
        }
        $probe = strlen($buffer) === self::MAX_BODY_BYTES ? $stream->read(1) : '';
        $this->bodyTruncated = $probe !== '';
        if ($seekable) {
            // Hand the stream back where the handler chain left it (normally 0).
            $stream->seek($start);
        } else {
            $this->unrewindableConsumed = $buffer . $probe;
        }
        $this->cachedBody = $buffer;
        $this->bodyRead = true;

        return $buffer;
    }

    /**
     * Bytes the body read took from a stream that cannot be rewound, or ''
     * when nothing was consumed (body never read, or the stream was rewound).
     */
    public function consumedFromUnrewindableStream(): string
    {
        return $this->unrewindableConsumed;
    }

    public function bodyWasTruncated(): bool
    {
        $this->body();

        return $this->bodyTruncated;
    }

    public function state(): RequestState
    {
        return $this->state;
    }

    /**
     * The wrapped PSR-7 server request, for adapter resolvers that inspect
     * framework-native surfaces (routing results, attributes, ...).
     */
    public function underlying(): ServerRequestInterface
    {
        return $this->request;
    }
}
