Release Notes
=============

___

v1.4.0 (2026-10-07)
-------------------

### Changed

- **Engine constraint repinned to guard-core-php ^4.3.1, the shipped parity release.** `composer.json` floors `rennf93/guard-core-php` at the released `^4.3.1` and `composer.lock` resolves it at v4.3.1 from packagist, replacing the dev-master alias; everything resolves from the registry with no path or VCS repository entries.

### Added

- **FP-PHP parity surface (PR #19).** Route patterns resolve most-specific-first (length-descending), so the longest matching path pattern wins regardless of insertion order, closing the first-match insertion-order divergence with the reference implementations. An optional `agentHandler` constructor argument wires a duck-typed agent (`sendEvent`) into the engine's event bus. `GuardStatusRequestHandler` (PSR-15 `RequestHandlerInterface`) serves `GuardEngine::initializationStatus()` as JSON, mirroring fastapi-guard's status route.

### Verification

- Local gates on php 8.5.11: `composer validate` exit 0, `composer audit --locked` clean (zero open advisories), `make lint` exit 0, and `bin/test_psr15.php` 112/112 checks green with host Redis on 6379 (includes the three new parity assertions), with `composer.lock` resolving guard-core-php v4.3.1. PHPStan level 8 via the `ghcr.io/phpstan/phpstan:latest` image: no errors. Dockerized live smoke (examples/simple_app, port 8080): all six workflow assertions green.

___

v1.3.0 (2026-10-01)
-------------------

### Changed

- **Engine floor raised to guard-core-php ^4.3.0, the safety-corpus release.** 1.3.0 floors `rennf93/guard-core-php` to ^4.3.0 and picks up the 4.3.0 engine train: the spec 12 event bus with dynamic rules and Redis-backed metrics persistence, the spec 10 geo download and refresh lifecycle, the spec 04 ReDoS safety gates over PCRE plus the performance monitor, and the pattern_safety / events / redis_interop conformance runners. Nothing in the PSR-15 surface changes: the middleware is a thin translation layer, so the new engine surfaces ride config through the unchanged `GuardMiddleware` option map. The CI engine checkout stamps the mounted sibling path checkout at 4.3.99 (ci.yml, release.yml, static-analysis.yml, upstream-drift.yml, scheduled-lint.yml) so the floor resolves against the engine master while keeping the published constraint ^4.3.0, and `composer.lock` resolves the engine at the released v4.3.0.

### Added

- **Process scaffold (PR #15).** `SECURITY.md` (supported versions, advisory reporting), `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, `.github/FUNDING.yml`, the PR template and the issue templates, modeled on the Go family and the fastapi-guard baseline. `.github/workflows/static-analysis.yml`: phpstan via the docker pattern these repos already use (composer:2 install with the same ci-only path-repository patch as ci.yml, then `ghcr.io/phpstan/phpstan:latest analyse src --level=8`), on push/pr to master plus the weekly Monday cron; level 8 is the highest level with zero findings on master (max reports one return-type generics finding). `.github/workflows/live-smoke.yml` gains the `live-smoke-advanced` job (the existing simple_app job untouched): a dockerized run of `examples/advanced_app` on host port 8081 asserting `GET /` and `GET /health` 200 plus one blocked-request check (`GET /admin/check?ip=203.0.113.9` without `X-Admin-Token` returns the admin gate's 400 "Missing required header: X-Admin-Token"). CodeQL was drafted and dropped after the first run proved GitHub CodeQL no longer supports PHP (`Did not recognize the following languages: php`); `.github/workflows/semgrep.yml` is the substitute, running the pinned semgrep/semgrep:1.177.0 container with the p/security-audit and p/secrets rulesets over src/ (path-filtered push/PR triggers plus a weekly Monday cron, metrics off).
- **100% line coverage gate (PR #16, the PHP-family pilot).** The bespoke `bin/test_psr15.php` suite stays as it is; `.github/coverage-runner.php` (phpunit/php-code-coverage ^11 + pcov) executes the suite under coverage and the new CI coverage job gates on 100.00% measured lines in src/. The suite already measured 100.00% lines locally (pcov, php 8.3), so no test changes were needed; the job mirrors ci.yml's path-repo sibling pattern (php 8.3, coverage: pcov) and suite pass/fail stays gated by the existing test job.

### Verification

- `make lint` exit 0 and `make test` exit 0 on php 8.5.11 (host Redis on 6379, 112/112 checks green), with `composer.lock` resolving guard-core-php v4.3.0.

___

v1.2.0 (2026-09-27)
-------------------

### Changed

- **Engine floor raised to guard-core-php ^4.2.0, the parity release.** The 4.1.0 family tags were a version-accuracy error and were yanked/unpublished, so the published ^4.1.0 constraint of v1.1.0 does not resolve publicly; 1.2.0 floors `rennf93/guard-core-php` to ^4.2.0 and picks up the 4.2.0 engine train: the on_block hook payloads now carry the reference log-format reasons, route-level IP rules (`RouteConfig` ipWhitelist/ipBlacklist/blockedCountries/whitelistCountries) are enforced, and the spec 4.1.0 corpus runs with an empty divergence registry (219 cases, pipeline gate 35 passed / 0 failed / 0 xfail). The CI engine checkout stamps the mounted sibling path checkout at 4.2.99 so the floor resolves against the engine master while keeping the published constraint ^4.2.0.

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
