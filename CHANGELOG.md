Release Notes
=============

___

v1.1.0 (unreleased)
-------------------

### Added

- **Parity pass-through surface.** `GuardMiddleware` now finishes pass-through responses the way the reference response factory does: the engine's security headers (`securityHeaders`) and CORS verdict headers (`enableCors`, `corsAllow*`) are merged onto the handler's response, and the engine's behavioral return rules observe the response status plus a body prefix bounded by `behavior_max_response_body_inspect_bytes` (captured from the PSR-7 stream only while `behavior_scan_response_body` is on, stream rewound afterwards when seekable), so `globalBehaviorRules` and route `behaviorRules` with `return_pattern` rules act on what the application actually served.
- **Per-route configuration.** The middleware takes `routes` (path pattern to `RouteConfig`, exact or trailing-slash prefix match) and an optional `routeResolver` closure receiving the raw PSR-7 server request, attaching per-route behavior rules, detection exclusions, rate-limit tiers and check bypasses to the engine's request state.
- **Geo rate-limit resolver.** A `geoRateLimitResolver` option injects the country resolver that powers `RouteConfig` `geoRateLimits` tiers; when absent, the middleware bridges the engine config's `geo_ip_handler` (kept by the engine only when country lists are configured).
- Reachability tests for every new surface in `bin/test_psr15.php` (108 checks green) and pass-through/route/geo documentation in the README and `docs/configuration.md`.

___

v1.0.0 (2026-09-24)
-------------------

First stable release (v1.0.0)
-----------------------------

### Added

- **PSR-15 middleware adapter for guard-core-php 4.0.4.** `GuardMiddleware` translates any PSR-7 `ServerRequestInterface` into the guard-core engine (`RenzoFranceschini\GuardCore\Engine\GuardEngine`) and translates block verdicts back to PSR-7 responses, so the guard works with Slim 4, Mezzio, Symfony PSR-15 bridges, or any PSR-7/PSR-15 stack.
- **Exact block translation.** Blocked requests get the engine's `BlockResponse` translated status-for-status, body-for-body and header-for-header: `403 Forbidden` for a blacklisted IP, `429 Too many requests` with `Retry-After` for a rate limit hit. Passing requests continue down the stack untouched.
- **Fail-closed behavior.** If the engine throws, the middleware returns the engine's fail-closed response (`500 Security check failed`, honorably overridden by `customErrorResponses`) instead of letting the request through.
- **Bounded body read.** The request body is scanned as a prefix of at most 256 KiB (`PsrGuardRequest::MAX_BODY_BYTES`, matching the engine's full-scan window).
- **Distributed state via Redis.** Distributed rate limits, IP bans, and cloud-range caches require Redis (`enableRedis: true`); `redisFailOpen: true` keeps serving when Redis is unreachable, `false` fails closed at construction.

### Changed

- **The engine dependency is pinned to `rennf93/guard-core-php` `^4.0.4`**, the first stable engine release, replacing the `^0.1.0` pin to the burned pre-release snapshot tag.

### Internal (v1.0.0)

- Plain-PHP test suite in `bin/test_psr15.php` (unit coverage always; Redis-backed shared-state integration cases run when `REDIS_HOST` points at a reachable Redis), plus a `php -l` sweep and `composer audit` in CI across PHP 8.2, 8.3 and 8.4.
- Community workflows, a MkDocs documentation site, and simple and advanced example apps landed via the parity-polish pass.

___
