<p align="center">
    <a href="https://guard-core.github.io/guard-core/latest/">
        <img src="https://guard-core.github.io/guard-core/latest/assets/guard_core_legend.svg" alt="Guard Core">
    </a>
</p>

___

<p align="center">
    <strong>PSR-15 middleware adapter for [guard-core-php](https://github.com/Guard-Core/guard-core-php): translates any PSR-7 `ServerRequestInterface` to the guard-core engine and translates block verdicts back to PSR-7 responses. Works with Slim 4, Mezzio, Symfony PSR-15 bridges, or any PSR-7/PSR-15 stack.</strong>
</p>

<p align="center">
    <a href="https://packagist.org/packages/rennf93/psr15-guard">
        <img src="https://img.shields.io/packagist/v/rennf93/psr15-guard?color=0080ff" alt="Packagist version">
    </a>
    <a href="https://guard-core.github.io/psr15-guard/latest/">
        <img src="https://img.shields.io/badge/docs-latest-0080ff.svg" alt="Docs">
    </a>
    <a href="https://github.com/Guard-Core/psr15-guard/actions/workflows/release.yml">
        <img src="https://github.com/Guard-Core/psr15-guard/actions/workflows/release.yml/badge.svg" alt="Release">
    </a>
    <a href="https://opensource.org/licenses/MIT">
        <img src="https://img.shields.io/badge/License-MIT-yellow.svg" alt="License">
    </a>
    <a href="https://github.com/Guard-Core/psr15-guard/actions/workflows/ci.yml">
        <img src="https://github.com/Guard-Core/psr15-guard/actions/workflows/ci.yml/badge.svg" alt="CI">
    </a>
</p>

<p align="center">
    <a href="https://github.com/Guard-Core/psr15-guard/actions/workflows/pages/pages-build-deployment">
        <img src="https://github.com/Guard-Core/psr15-guard/actions/workflows/pages/pages-build-deployment/badge.svg?branch=gh-pages" alt="PagesBuildDeployment">
    </a>
    <a href="https://github.com/Guard-Core/psr15-guard/actions/workflows/docs.yml">
        <img src="https://github.com/Guard-Core/psr15-guard/actions/workflows/docs.yml/badge.svg" alt="DocsUpdate">
    </a>
    <img src="https://img.shields.io/github/last-commit/Guard-Core/psr15-guard?style=flat&amp;logo=git&amp;logoColor=white&amp;color=0080ff" alt="last-commit">
</p>

<p align="center">
    <img src="https://img.shields.io/badge/PHP-777BB4.svg?style=flat&logo=php&logoColor=white" alt="PHP">
    <a href="https://packagist.org/packages/rennf93/psr15-guard">
        <img src="https://img.shields.io/packagist/dm/rennf93/psr15-guard" alt="Downloads">
    </a>
</p>

<p align="center">
    <a href="https://guard-core.com">Website</a> &middot;
    <a href="https://guard-core.github.io/psr15-guard/latest/">Docs</a> &middot;
    <a href="https://playground.guard-core.com">Playground</a> &middot;
    <a href="https://app.guard-core.com">Dashboard</a> &middot;
    <a href="https://discord.gg/ZW7ZJbjMkK">Discord</a>
</p>

---

## Install

```bash
composer require rennf93/psr15-guard
```

## Usage

```php
use Nyholm\Psr7\Factory\Psr17Factory;
use RenzoFranceschini\GuardCore\Config\SecurityConfig;
use RenzoFranceschini\GuardCore\Engine\GuardEngine;
use RenzoFranceschini\GuardCorePsr15\GuardMiddleware;

$config = new SecurityConfig(
    enableRedis: false,
    blacklist: ['192.0.2.0/24'],
    rateLimit: 100,
    rateLimitWindow: 60,
    enableRateLimiting: true,
);

$factory = new Psr17Factory();
$engine = new GuardEngine($config);
$guard = new GuardMiddleware($engine, $factory, $factory);

$app->add($guard);
```

Blocked requests get the engine's `BlockResponse` translated exactly (status, body, headers), for example `403 Forbidden` for a blacklisted IP, `429 Too many requests` with `Retry-After` for a rate limit hit. Passing requests continue down the stack untouched.

Pass-through responses are finished by the middleware too: the engine's security headers and CORS verdict headers are merged on top of the handler's response, and the engine's behavioral return rules observe the response status code plus a body prefix bounded by `behaviorMaxResponseBodyInspectBytes` (only while `behaviorScanResponseBody` is on; the PSR-7 stream is rewound after the bounded capture when it is seekable, so emitters still see the full body). Per-route configuration attaches through the middleware's route map or a custom resolver:

```php
use RenzoFranceschini\GuardCore\Routing\RouteConfig;
use RenzoFranceschini\GuardCorePsr15\GuardMiddleware;

$middleware = new GuardMiddleware(
    $engine,
    $responseFactory,
    $streamFactory,
    routes: [
        '/docs/' => new RouteConfig(enableSuspiciousDetection: false),
        '/api/' => new RouteConfig(rateLimit: 5, rateLimitWindow: 10),
    ],
    // optional: country resolver for RouteConfig geoRateLimits tiers
    geoRateLimitResolver: $myResolver,
    // optional: custom resolver receiving the PSR-7 server request
    routeResolver: fn (ServerRequestInterface $r) => str_starts_with($r->getUri()->getPath(), '/admin') ? new RouteConfig(bypassedChecks: ['rate_limit']) : null,
);
```

`routes` patterns match a path exactly or as a prefix when they end with `/`. Geo rate-limit tiers need a country resolver: pass `geoRateLimitResolver` explicitly, or configure `geoIpHandler` together with `blockedCountries`/`whitelistCountries` (the engine keeps the injected handler only when country lists are set) and the middleware bridges it onto the engine's rate-limit handler automatically.

## Lifecycle

PHP shared-nothing applies: construct `GuardEngine` (and therefore `GuardMiddleware`) per request in classic FPM, or per worker under Swoole/Octane/RoadRunner. The middleware holds no mutable state of its own. In-memory fallbacks are per-request safety nets; distributed rate limits, IP bans, and cloud-range caches require Redis (set `enableRedis: true` and point `REDIS_HOST`/`REDIS_PORT` at your instance).

## Behavior notes

- Fail-closed: if the engine throws, the middleware returns the engine's fail-closed response (`500 Security check failed`, honorably overridden by `customErrorResponses`) instead of letting the request through.
- Bounded body read: the request body is scanned as a prefix of at most 256 KiB (`PsrGuardRequest::MAX_BODY_BYTES`, matching the engine's full-scan window). Payloads beyond the prefix, or signatures split across its boundary, are not detected.
- With `redisFailOpen: true` the middleware constructs and serves requests even when Redis is unreachable; with `redisFailOpen: false` construction fails closed.
- No security headers or CORS are added by this adapter.

## Testing

```bash
composer lint
composer test
```

`composer test` runs the plain-PHP suite in `bin/test_psr15.php` (unit coverage always; set `REDIS_HOST` to a reachable Redis to include the shared-state integration cases).

## License

MIT
