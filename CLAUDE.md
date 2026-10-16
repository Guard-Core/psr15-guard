# AGENTS.md
Guidance for AI agents (including Claude Code) working in this repository.

## Project Overview

rennf93/psr15-guard (https://github.com/Guard-Core/psr15-guard) is a PSR-15 middleware adapter for guard-core-php. It translates any PSR-7 `ServerRequestInterface` into the guard-core engine and translates the engine's block verdicts back to PSR-7 responses. It works with Slim 4, Mezzio, Symfony PSR-15 bridges, or any PSR-7/PSR-15 stack.

- Composer package `rennf93/psr15-guard`, type `library`, license MIT. There is no `version` field in composer.json: versions come from git tags (`vX.Y.Z`) and Packagist derives them.
- This repository contains NO security logic. Detection, rate limiting, bans, and verdicts all live in guard-core-php.
- PHP `^8.2`. Autoload is PSR-4: `RenzoFranceschini\GuardCorePsr15\` maps to `src/`.
- Releases are tag driven (v1.1.0 through v1.5.0 are shipped). `master` is protected: never push to it, never merge into it, never create tags or releases as part of agent work.
- Docs site: MkDocs Material in `docs/` (strict build in CI, gh-deploy on push to `master` touching docs sources). Runnable demos in `examples/` are exercised by the live-smoke workflow.

## Ecosystem Position

- `rennf93/guard-core-php` (https://github.com/Guard-Core/guard-core-php) is the engine. It owns `SecurityConfig`, `GuardEngine`, the `GuardRequest`/`GuardResponse` contracts, `HeaderBag`, `RequestState`, `RedisHandler`, `GuardRedisException` and `SecurityDecorator`. Every check, verdict, and block response body originates there.
- This package is the PSR-7/PSR-15 adapter for that engine: `GuardMiddleware` implements `MiddlewareInterface`, `PsrGuardRequest` implements `GuardRequest`, `ResponseTranslator` converts `GuardResponse` objects to `ResponseInterface`, and `GuardStatusRequestHandler` serves the engine's initialization status as JSON.
- `rennf93/slim-guard` composes this middleware; a change to the `GuardMiddleware` constructor or behavior reaches Slim users through it.
- Composer constraint: `rennf93/guard-core-php: ^4.4.0` (composer.json `require`), resolved from Packagist. `composer.lock` pins the released engine (v4.4.0). There are no `repositories` entries and no path repository.
- The engine's agent seam: an optional `agentHandler` (for example `rennf93/guard-agent-php`, listed under `suggest`) is passed to the constructor and wired with `GuardEngine::setAgentHandler()`. `SecurityConfig::enableAgent` declares it, and `enableDynamicRules` and `enableEnrichment` require `enableAgent` (the engine throws at config validation otherwise).
- `minimum-stability: dev` with `prefer-stable: true` stays in composer.json.

## Boundary Rules

The adapter adapts; the engine decides.

- MUST NOT implement detection rules, penetration signatures, rate limiting, IP blacklisting or whitelisting, banning, security headers, or CORS. The adapter only copies the engine's decisions: the security header set (`GuardEngine::responseHeaders()`), the CORS verdict headers (`corsResponseHeaders()`) and the output of `customResponseModifier` (`applyResponseModifier()`) are merged onto pass-through responses, and nothing is computed here.
- MUST depend on guard-core-php for every verdict. `GuardMiddleware::process()` calls `GuardEngine::execute()` and acts only on its return value (`null` means pass, a `GuardResponse` means block).
- MUST keep framework-agnostic PSR compliance. `src/` imports only `Psr\Http\*` interfaces and `RenzoFranceschini\GuardCore\*` classes. No framework imports, ever.
- MUST stay fail-closed: if the engine throws, `process()` returns the engine's fail-closed response (`500 Security check failed`, overridable through the engine's `customErrorResponses`), never the downstream handler.
- MUST keep the middleware stateless apart from the injected collaborators: PHP shared-nothing applies (per request under classic FPM, per worker under Swoole/Octane/RoadRunner); distributed rate limits, bans, and cloud-range caches require Redis.
- MUST NOT widen the bounded body read: `PsrGuardRequest::MAX_BODY_BYTES = 262144` (256 KiB) matches the engine's full-scan window. Payloads beyond the prefix, or signatures split across its boundary, are not detected; that tradeoff is deliberate and engine-coupled.

The real adapter surface (all of `src/`, four final classes in namespace `RenzoFranceschini\GuardCorePsr15`):

- `GuardMiddleware implements MiddlewareInterface`. Constructor: `GuardEngine $engine`, `ResponseFactoryInterface`, `StreamFactoryInterface`, then optional `array $routes`, `?Closure $routeResolver`, `?CountryResolver $geoRateLimitResolver`, `?object $agentHandler` and `?SecurityDecorator $decoratorHandler`. The constructor calls `$engine->initialize()`; a `GuardRedisException` is swallowed only when `$engine->config()->redisFailOpen` is true, otherwise construction fails closed by rethrowing. `process()` wraps the PSR-7 request in `PsrGuardRequest`, attaches the resolved `RouteConfig` (longest path pattern wins, a `"GET /path"` method prefix scopes a pattern, an explicit entry wins over a decorator entry), runs the engine, translates block verdicts via `ResponseTranslator`, and on pass hands the ORIGINAL PSR-7 request to the downstream handler. Pass-through responses go through `finish()`: the behavioral return rules (`processResponse`), the engine security headers, the engine `customResponseModifier` (`applyResponseModifier()`, once, only when a modifier is configured), then the CORS verdict headers. Public helpers: `agentStats()`, `reset()` and `refreshCloudIpRanges()`.
- `PsrGuardRequest implements RenzoFranceschini\GuardCore\Request\GuardRequest`. Adapts URI path, scheme, full URL, and a pure `urlReplaceScheme()`; upper-cased method; client host from the `REMOTE_ADDR` server param (null when missing or empty); headers into a `HeaderBag` with joined header lines; query params passthrough; and the body as a cached, bounded prefix with `bodyWasTruncated()`. Each instance carries its own `RequestState`.
- `ResponseTranslator`. `translate(GuardResponse): ResponseInterface` copies status code, body (null becomes an empty stream), and all headers onto a response built from the injected PSR-17 factories. Exact translation, no additions.
- `GuardStatusRequestHandler implements RequestHandlerInterface`. Serves `GuardEngine::initializationStatus()` as JSON (200, `Content-Type: application/json`); the consumer registers it on their router.

## Quick Start

Use the host `php` and `composer` when present (`make` falls back to Docker `php:8.3-cli` and `composer:2` when they are missing).

1. `composer install --no-interaction --no-progress` (or `make install`). The lock resolves guard-core-php from Packagist.
2. `make lint` (php -l sweep plus `composer validate --strict`), then `make test`.

Redis-backed assertions need a reachable Redis: `REDIS_HOST` (default 127.0.0.1) and `REDIS_PORT` (default 6379). Point `REDIS_PORT` at a private `redis-server` when you do not want to touch a shared instance. The suite keys everything under a random `REDIS_PREFIX` and cleans up after itself.

The CI path (from `.github/workflows/ci.yml`, same install, lint and test steps in `release.yml`):

1. Set up PHP from the matrix (8.2, 8.3, or 8.4) with the `mbstring` extension, coverage none.
2. `composer install --no-interaction --no-progress`.
3. The php -l sweep, then `REDIS_HOST=127.0.0.1 php bin/test_psr15.php` against a `redis:7-alpine` service on port 6379.
4. A separate job runs `composer audit`, and a coverage job runs `php .github/coverage-runner.php bin/test_psr15.php` with pcov and fails below 100% line coverage of `src/`.

## Development Commands

`Makefile` targets: `install`, `test`, `lint`, `bump-version VERSION=x.y.z` and `clean`. Composer scripts (composer.json `scripts`):

| Command | What it runs |
| --- | --- |
| `composer test` | `php bin/test_psr15.php` |
| `composer lint` | `for f in $(find src bin -name '*.php'); do php -l "$f" > /dev/null || exit 1; done && echo LINT_OK` |
| `make bump-version VERSION=x.y.z` | `.github/scripts/bump_version.py`: scaffolds the CHANGELOG entry only (PHP packages carry no version file) |
| `composer audit` | Advisory check of the locked dependencies (CI audit job, PHP 8.3) |
| `php .github/coverage-runner.php bin/test_psr15.php` | 100% line coverage gate over `src/` (needs the pcov or xdebug extension) |
| `mkdocs build --strict` | Build the docs site (run with `docker run --rm -v "$PWD":/work -w /work python:3.12-slim sh -c "pip install -q mkdocs-material && mkdocs build --strict"`; the `site/` output is gitignored) |
| `docker compose -f examples/simple_app/docker-compose.yml up --build -d --wait` | Bring up the simple example app plus Redis; then run the curl assertions from `.github/workflows/live-smoke.yml` |
| `docker compose -f examples/advanced_app/docker-compose.yml up --build -d --wait` | Same for the advanced example (assertions in `examples/advanced_app/README.md`) |

`bin/` contains exactly one script: `bin/test_psr15.php` (the whole test suite, plain PHP, no PHPUnit). PHPStan runs only in CI (`static-analysis.yml`, level 8 over `src/` through the `ghcr.io/phpstan/phpstan` image); there is no PHP-CS-Fixer.

## Project Structure

```
.github/dependabot.yml                Weekly dependabot: github-actions + composer (grouped)
.github/labels.yml                    Label registry for sync-labels
.github/labeler.yml                   PR area-label rules for labeler
.github/coverage-runner.php           100% line coverage gate (php-code-coverage) used by CI
.github/scripts/bump_version.py       CHANGELOG scaffold used by make bump-version
.github/workflows/ci.yml              CI: test matrix php 8.2/8.3/8.4 + redis service, composer audit, 100% coverage
.github/workflows/release.yml         Release Gate: test matrix and composer audit on v* tags (no coverage job)
.github/workflows/static-analysis.yml PHPStan level 8 over src/ (push, PR, weekly cron)
.github/workflows/semgrep.yml         Semgrep p/security-audit and p/secrets over src/
.github/workflows/scheduled-lint.yml  Weekly cron (Mon 04:00 UTC): php -l sweep + composer audit
.github/workflows/issue-link.yml      PR must close an open issue or carry no-issue
.github/workflows/upstream-drift.yml  Daily suite run against guard-core-php@master
.github/workflows/live-smoke.yml      Dockerized compose smoke over examples/simple_app and advanced_app
.github/workflows/docs.yml            mkdocs strict build + gh-deploy on master docs changes
.github/workflows/container-release.yml  Publishes examples/advanced_app image to ghcr.io
.github/workflows/discord-dev-feed.yml, summary.yml, sync-labels.yml, greetings.yml, labeler.yml, stale.yml  Repo housekeeping
bin/test_psr15.php                    Entire test suite, plain PHP runner with a T assertion harness
composer.json / composer.lock         Package metadata, autoload, scripts; the lock is tracked
docs/index.md, usage.md, configuration.md  Docs site pages
examples/simple_app/, advanced_app/   Demo apps (compose app + redis), live-smoke targets
mkdocs.yml                            MkDocs Material site definition
CHANGELOG.md                          Release notes, newest first (scaffolded by bump_version.py)
```

- `src/` is the PSR-4 package root. It is flat: one final class per file, class name equals file name, no subdirectories. A fifth class would be a real surface change: update the README behavior notes in the same PR.
- `vendor/` exists on disk but is gitignored. Never `git add` it. Refresh the lock deliberately with `composer update rennf93/guard-core-php`.

## Technology Stack

- PHP `^8.2` (CI matrix: 8.2, 8.3, 8.4; the audit and coverage jobs run on 8.3).
- Runtime deps (composer.json `require`, locked versions): `rennf93/guard-core-php ^4.4.0` (v4.4.0), `psr/http-message ^1.1|^2.0` (2.0), `psr/http-factory ^1.0` (1.1.0), `psr/http-server-handler ^1.0` (1.0.2), `psr/http-server-middleware ^1.0` (1.0.2).
- Dev deps: `nyholm/psr7 ^1.8` (used only by `bin/test_psr15.php`) and `phpunit/php-code-coverage ^11.0` (only the coverage gate). `suggest`: `rennf93/guard-agent-php`.
- CI runs a `redis:7-alpine` service container on port 6379 with health checks for the Redis integration tests.
- Actions are pinned by commit SHA.
- Examples run on `php:8.3-cli-alpine` (PHP built-in webserver, non-root in the advanced app) with composer builds from `composer:2`; Redis is `redis:7-alpine`.
- Docs site: mkdocs-material, strict build, deployed to GitHub Pages by `docs.yml` on `master` pushes touching docs sources.

## Testing Guidelines

- Run with `make test`, `composer test` or `php bin/test_psr15.php`. Exit code 0 means green, 1 means red. Output: `ok - <label>` or `FAIL - <label>` per assertion, `=== section ===` headers, and a final `Passed: N, Failed: N` plus `N/N GREEN` or `N/N RED`.
- Redis integration: the runner attempts a socket connection to `REDIS_HOST` (default 127.0.0.1) and `REDIS_PORT` (default 6379). Set `REDIS_HOST=0` to force integration off; if no Redis is reachable it prints a SKIP line and unit coverage stands.
- Coverage areas (section names in `bin/test_psr15.php`): PSR-7 to GuardRequest translation; the bounded body read at exactly 262144 bytes; block verdicts through the middleware; pass-through (handler called exactly once with the original PSR-7 request); the response modifier on pass-through responses; route patterns, decorator handler and geo rate-limit wiring; the agent handler seam and `agentStats()`; the status handler; fail-closed on engine malfunction; and Redis fail-open versus fail-closed construction.
- Any new engine behavior that flows through the adapter needs assertions here before the PR lands, and `src/` must stay at 100% line coverage.

## Code Quality Standards

- `declare(strict_types=1);` at the top of every PHP file in `src/` and `bin/`.
- `final` classes, `private readonly` promoted constructor properties, 4-space indentation, one class per file named after the class.
- Imports in `src/` are limited to `Psr\Http\*` and `RenzoFranceschini\GuardCore\*` (plus `Closure`). A new import outside those families is a boundary violation (see Boundary Rules).
- The php -l sweep over `src` and `bin` must pass; `composer lint` prints `LINT_OK` on success.
- Conventional commits are the house style (see `git log`): `feat:`, `fix:`, `fix(deps):`, `ci:`, `chore:`, `chore(composer):`, `test:`, `docs:`. Lowercase, imperative, no attribution trailers. Squash subjects end with `(#N)`.
- The examples are demo code, not package surface: they live under `examples/`, carry their own composer.json (no committed lock file), and must never be autoloaded by the library.

## Best Practices

- `master` is protected. Work on a branch, push, open a PR that closes an open issue. Never push to `master`, never tag, never publish a release as part of agent work.
- Never `git add vendor/`, `.DS_Store`, or any stray file. Stage explicit paths only.
- Keep the adapter thin. If a change adds detection, verdict logic, or response shaping beyond translation, it belongs in guard-core-php, not here.
- Do not hardwire a PSR-7 implementation: the middleware takes `ResponseFactoryInterface` and `StreamFactoryInterface` so consumers pick their own PSR-17 factory (the tests use nyholm/psr7).
- Preserve fail-closed semantics: never let an engine exception fall through to the downstream handler.
- Update README.md behavior notes in the same PR whenever adapter behavior changes.

## Related Projects

- `rennf93/guard-core-php`: https://github.com/Guard-Core/guard-core-php. The engine this adapter delegates to; resolved from Packagist.
- `rennf93/guard-agent-php`: https://github.com/Guard-Core/guard-agent-php. The optional telemetry agent passed as `agentHandler`.
- `rennf93/slim-guard`: https://github.com/Guard-Core/slim-guard. Composes this middleware for Slim 4.
- `rennf93/psr15-guard`: this repository, the PSR-15 adapter layer of the guard-core ecosystem.
