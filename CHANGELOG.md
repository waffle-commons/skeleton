# Changelog — waffle-commons/skeleton

All notable changes to this component are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Released in lockstep with the Waffle Commons umbrella tag.

## [Unreleased]

### Added
- The kernel factory registers the PSR-17 `ServerRequestFactoryInterface` (the `http` component's
  `ServerRequestFactory`). The `waffle-commons/testing` bridge builds its requests with it and the stream
  factory, without importing `http` (CARRY-04, TEST-01). A test pins the registration.
- **Orchestrator probes (OPS-01).** The kernel factory mounts `pipeline`'s `ProbeMiddleware` after `/waffle-metrics` and before tracing and `TrustedHostMiddleware`, so `/healthz` and `/readyz` answer whatever the `Host` header says and never inflate the request metrics. `/readyz` probes the relational pool (`db.pool`, required by the new `waffle.health.required` key) and, when the cache is Redis-backed, the cache; the per-probe report goes to localhost only, like `/waffle-metrics`. The data services are now built before the middleware stack so the probes can use them, and the cache probe reads the raw cache, so probes do not skew the cache hit ratio. Tests drive both endpoints through the real kernel.
- **Graceful shutdown (OPS-02).** `/readyz` turns 503 before the ports close, so the load balancer stops routing first, along one of two paths. Under Kubernetes, the pod's `preStop` hook creates the drain marker (`waffle.health.drain_marker`, `/tmp/waffle-draining`) and sleeps before SIGTERM. Without a `preStop` hook (Docker, Compose, ECS), `CADDY_SHUTDOWN_DELAY` keeps the server answering after SIGTERM while Caddy itself returns the public 503 body on `/readyz`; it defaults to `0s` (Kubernetes has already flipped) and `docker-compose.prod.yml` sets `5s`. Caddy's `grace_period` (`CADDY_GRACE_PERIOD`, 25 s) then drains the requests in flight, and `docker-compose.prod.yml` sets `stop_grace_period: 35s`, since Docker's 10-second default killed the drain. The SIGTERM path needs FrankenPHP 1.13.1 or later: 1.12.7 answered 500 to every PHP request during the delay. The monorepo's `scripts/drain-check.sh` checks both paths on a given image.
- **Rate limiting and outbound resilience (NET-01/02/03).** `RateLimitFactory` mounts `security`'s `RateLimitMiddleware` right after authentication: a token bucket in Redis per authenticated subject, otherwise per client IP resolved behind `waffle.security.rate_limit.trusted_proxies`, 120 requests per 60 s by default, letting traffic through when Redis is unavailable (`on_storage_failure: open`). `HttpClientFactory` wraps both outbound clients (`ClientInterface` and `ConcurrentClientInterface`) in `RetryingClient(CircuitBreakerClient(…))`: transfer timeouts, bounded retries of idempotent methods and a per-origin circuit breaker whose state lives in Redis (`waffle.http_client.resilience.*` and `waffle.http_client.breaker.*`, RFC-024 §5.3). The authenticated client is now `AuthenticatedSettlingClient`, so a batch sent through it stays one concurrent batch. Both read their Redis from a `redis_dsn` key fed by `REDIS_DSN`, through a phpredis pool (`RedisPoolFactory`), so the image installs the `redis` extension and `ext-redis` is required.
- **Migration lifecycle (OPS-03).** `bin/waffle` registers `make:migration`, `db:status` and `db:rollback` next to `db:migrate`; `make:migration` writes into `waffle.database.migrations_path` with the system clock. The sample `Version2026053101_CreateUsersTable` migration gains its down script (`DROP TABLE IF EXISTS users`), so `db:rollback` can revert it.

### Changed
- **Dev profiler headers in debug mode (DXP-01).** `ProfilerFactory` wraps the tracer in `QueryCountingTracer` and puts `ProfilerHeadersMiddleware` first in the stack, only when `$debug` is true, which `AppKernelFactory` already refuses in production: every response then carries `X-Waffle-Time`, `X-Waffle-Memory`, `X-Waffle-Queries` and `Server-Timing`. `X-Waffle-Queries` counts the queries the data layer's repositories trace; the demo controllers query a pooled connection directly, so they report 0.
- **The connection pool skips its liveness ping for 500 ms after a connection was proven alive (CARRY-01).** `ConnectionPoolFactory` passes `waffle.database.ping_freshness_ms` (`DB_PING_FRESHNESS_MS`, default 500, `0` pings on every lease) to `PDOConnectionPool`. Under load the `SELECT 1` before each lease, two round trips under PostgreSQL, all but disappears. The trade-off, documented in `config/app.yaml`: when the database drops its connections, requests can fail for up to the window. `/readyz` stays truthful, since its probe asks the leased connection itself.
- `waffle.debug`, `waffle.log.channel` and `waffle.log.stream` are gone from `config/app.yaml`: nothing read them (`public/index.php` reads `APP_DEBUG` itself, and the kernel factory builds its loggers with their channels). A Redis outage under the rate limiter is now counted in `waffle_ratelimit_storage_errors_total{mode}` on `/waffle-metrics` (the kernel factory hands the limiter its metrics registry; `RateLimitFactory::register()` gave way to `middleware()`).
- The PHPUnit configuration moved from `temp_config/app.yaml` to `tests/config/app.yaml` (`APP_CONFIG` in `tests/bootstrap.php`). It sat at the root of every generated project under a name borrowed from the framework components' runtime test directories, and looked like a leftover; it now lives next to the tests that load it, and outside the Docker build context.
- `App\Security\RouteParamSubjectResolver` reads the route params through `Constant::ATTR_PARAMS` instead of the `'_params'` literal, now that `waffle-commons/contracts` names the attribute (CARRY-06).

### Fixed
- **Event listeners in `src/EventListener/` are discovered.** `waffle.paths.event_listeners` pointed at `app/EventListener`, which the skeleton does not have, and the kernel factory passed the path as is, so it depended on the worker's working directory: a listener added there was never registered. The key now reads `src/EventListener`, resolved under `APP_ROOT` like `controllers` and `services`.
- **The development stack boots out of the box.** `config/app.yaml` selects the Redis cache adapter, but the dev compose had no Redis and `.env.example` set `REDIS_DSN=null`: the router's cache threw `CacheBackendUnavailableException` and the kernel never booted, `/healthz` included. The tests hid it, because their configuration uses the array cache. The dev compose now runs a `waffle-redis` service the application waits for, and `.env.example` points `REDIS_DSN` at it. A project generated earlier fixes its own `.env` the same way.
- **The production image can run `db:migrate`.** The prod stage now copies `bin/` and `migrations/`, so a Kubernetes Job runs `php bin/waffle db:migrate` from the release image (OPS-03, RFC-014).
- **A fresh clone builds (CARRY-08).** The builder copied `composer.lock`, which the skeleton does not version, so `docker build` failed at that `COPY`; it is now optional (`composer.lock*`). A project made by `composer create-project` carries the chosen version in its composer.json and builds as is; the skeleton's own repository requires `self.version`, which Composer cannot resolve without `.git` or a lock, so building it from a fresh clone takes `--build-arg COMPOSER_ROOT_VERSION=…`.
- **The production compose boots again.** Its four tmpfs mounts (`/tmp`, `/config`, `/data`, `/app/var`) declared `mode: 1777`, which Compose reads as a decimal number, octal 3361: the non-root `waffle` user could write none of them, and once the upload root (`var/uploads`) had to be created at boot, the workers failed there and the server never started. The mounts now declare `mode: 0o1777`. A project generated earlier fixes its own `docker-compose.prod.yml` the same way.

### Security
- **The image runs FrankenPHP 1.13.2 on PHP 8.5.12** (was 1.12.7 on PHP 8.5.11), with the base image still pinned by digest. PHP 8.5.12 fixes PHP2Shell (CVE-2026-109618 and CVE-2026-109619), two critical unauthenticated remote code executions that only the upgrade fixes. FrankenPHP 1.13.x also fixes its own advisories since 1.12.7, among them header spoofing through dot-form names (GHSA-qcrp-8483-f2f2), `putenv()` leaking across requests (GHSA-996f-w38m-f574) and, in worker mode, `error_get_last()` / `libxml_get_errors()` carrying a previous client's data into the next request (GHSA-4gfw-9v29-gp9h). It ships Caddy 2.11.7, which caps request headers at 16 KiB and drops header names that contain a dot. A project generated from an earlier skeleton gets the fix by setting the same `FRANKEN_VERSION` and `FRANKEN_DIGEST` in its `docker/Dockerfile`.

## [0.1.0-beta6] — 2026-09

**Theme: audit remediation, and the template defects a real benchmark exposed.**

### Fixed
- **Event-listener discovery registered nothing.** `EventListenerDiscovery` built each
  candidate FQCN by concatenating the tokens that follow `namespace`, whitespace
  included — so every namespaced listener resolved as `" App\…\Listener"`, with a
  leading space, which `class_exists()` never matches. Every discovered listener was
  therefore skipped in silence: an application dropping a `#[AsEventListener]` class
  into the configured directory saw it simply never fire. The scanner now ignores
  whitespace tokens (the framework's own `Waffle\Commons\Utils\Service\ClassParser`
  already did). Found by covering the class with tests for the first time.
- `apcu_enabled()` is called behind a `function_exists()` guard. Without APCu the documented no-op fallback (`NullMetricsRegistry`) could never be reached — the kernel fatalled with `Call to undefined function apcu_enabled()` instead.
- **`composer.lock` is no longer shipped.** As the `composer create-project` target, a committed lock pinned every new application to the component SHAs frozen at release time — `create-project` at the beta6 tag would have installed beta5 framework code, without any of the audit remediations. The lock is now gitignored, so `self.version` resolves each `waffle-commons/*` package to the skeleton's own tag (matching `symfony/skeleton`).

### Fixed
- **The production image shipped no PostgreSQL or MySQL PDO driver.** The template
  configures `waffle.database.*`, provides migrations, wires a connection pool and
  exposes database-backed demo routes — none of which could work in its own image,
  which only carried `pdo_sqlite`. Surfaced by running the prod image under load
  for the first time.
- **`/greet` and `/register` answered 405 to the calls their own documentation
  described.** Both hydrate a DTO from the request body but declared no `methods`,
  so they fell back to the GET/HEAD/OPTIONS default.
- **Public demo routes returned 403 through the real pipeline.** The SEC-05
  method-level `#[PublicAccess]` migration missed `/`, `/hello/{name}`, `/greet`,
  `/crash`, `/register`, `/auth/demo-token` and `/api/me`. Controller tests never
  caught it because they invoke controllers directly and bypass the pipeline.
  `/api/me` was the subtle case: it enforces authentication itself, so the
  auth-bridge demo was unreachable with or without a valid token.
- **`/auth/demo-token` is now refused outside `dev` (404).** It mints a JWT signed
  with the production bridge secret; reachable in production it would be an
  anonymous token factory. A real application deletes the route in favour of its
  IdP.
- **A malformed `config/app.yaml` no longer boots production on defaults.** The
  `Failsafe::ENABLED` retry discards the entire configuration — trusted hosts, the
  CORS allow-list, the SecureContainer level, the CSRF and auth secrets — so a typo
  silently started a security-degraded worker. Production now refuses to start and
  says why; the developer-friendly fallback stays outside production.
- **The default database pairing could not work:** `driver: mysql` on port 3306
  with no such service in `docker-compose.yml`, and `.env.example` still pointing
  at `127.0.0.1:3306`. Now PostgreSQL end to end, with the matching
  `waffle-postgres` service.
- An unrecognised `waffle.database.driver` no longer silently builds a MySQL DSN.
- `APP_ENV=prod` with `APP_DEBUG=true` aborts the boot (FIX-01 #9), and the
  production image drops root (FIX-01 #13).

### Added
- `GET /read/demo` — a pooled read counterpart to `/write/demo`, returning
  `{found, user}` with HTTP 200 on both hit and miss. It deliberately does not
  select `email`: the route is `#[PublicAccess]`, and a reference template should
  not model returning PII from an unauthenticated endpoint.
- `App\Factory\ConnectionPoolFactory` — DSN grammar per engine and pool-size
  validation extracted from the kernel factory, with the pool ceiling driven by
  `DB_POOL_SIZE` (default 8).
- `App\Security\RouteParamSubjectResolver` — an example of the SEC-05
  object-level ABAC seam, deliberately **not** wired: handing voters the raw
  route-parameter array as the "domain subject" is a type-confusion footgun in a
  template. The opt-in is one commented line in `AppKernelFactory`.

### Documentation
- The README's routing example could not run: it used `method:` (the parameter is
  `methods`, a list), imported `Route` from the wrong namespace, and omitted
  `#[PublicAccess]` under fail-closed ABAC.

## [0.1.0-beta5] — 2026-07-08

**Theme: Beta5 demo app — passkeys, telemetry & APCu.**

### Added

- **WebAuthn / passkey demo (AXE6 / AUTH-01).** New `WebAuthnController` plus the
  application-provided, worker-resettable `InMemoryCredentialRepository` and
  `InMemoryChallengeStore`. `AppKernelFactory` wires the stateless
  `WebAuthnLibAdapter`, a `WebAuthnCeremony` (options issuance) and a
  `WebAuthnAuthenticator` (assertion verification) into the Universal Authentication
  Bridge alongside the existing JWT and HMAC-assertion schemes. New
  `waffle.security.webauthn` config block (`rp_id`, `rp_name`, `allowed_origins`).
- **Telemetry `/waffle-metrics` endpoint (AXE5 / OBS-02).** `MetricsMiddleware` exposes
  a Prometheus exposition endpoint backed by an APCu shared-memory metric store, with a
  no-op (`NullMetricsRegistry`) fallback when APCu is unavailable. Ships `MemoryCollector`,
  `GcCollector` and `PoolUtilizationCollector`, plus a `TracingMiddleware` that opens the
  root server span. A `NullTracer` + W3C no-op propagator are registered by default
  (zero overhead); the `waffle-commons/telemetry-otel` bridge is opt-in.
- **Concurrent / async / reactive demo controllers.** New `ConcurrentDemoController`
  (outbound HTTP fan-out via `ConcurrentClientInterface`), `AsyncDemoController` with a
  finish-request `LogAuditTask` (ASYNC-01 deferred task runner), and `ReactiveDemoController`
  driving the `#[Broadcast]` write-hook → SSE transport path (REACTIVE-01).
- **AOT compile commands (AXE1 / RFC-019).** `bin/waffle` registers
  `container:compile` (reflection-free `CompiledContainer`) and `route:compile`
  (serialized route trie) for the `WAFFLE_AOT=1` fast path, with reflection fallback.
- **Write demo (DBAL).** New `WriteDemoController` and `OrderStatus` DTO exercising the
  transaction-isolation write path.

### Changed

- **Security-context-aware container (AUTHZ-01).** `SecureContainer` now receives the
  request `SecurityContext` so `#[Voter]` checks see the authenticated identity. `bin/waffle`
  passes a blank `SecurityContext` for the CLI (voters do not run off the HTTP request).
- **Constructor-injected kernel (ARCH-03).** `AppKernelFactory` builds the `Kernel` via
  constructor injection (config, secure container, security, middleware stack, logger);
  the defensive `setConfiguration`/`setSecurity`/`set*` setter path is removed, with the
  event dispatcher remaining the sole boot-time setter.
- **Transaction isolation middleware (DBAL-02).** Write requests are wrapped in a single
  pinned-connection transaction (commit on success, rollback on uncaught exception); the
  relational pool is registered under `RelationalConnectionPoolInterface`.
- **Distributed-tracing propagation.** The HTTP client is constructed with the shared
  tracer + W3C propagator so outbound requests carry the active `traceparent` once the OTel
  bridge is attached; the same client is exposed as `ConcurrentClientInterface`.
- **Docker / compose.** Enabled APCu in CLI mode (`apc.enable_cli=1`) in the Dockerfile so
  the metric store is usable from console commands, and added `container_name`
  (`waffle-app-dev` / `waffle-app-prod`) to the dev and prod compose services.
- **Static-analysis profile.** `mago.toml` enables the cyclomatic-complexity metric with a
  threshold of 50.

### Dependencies

- Added `waffle-commons/async`, `waffle-commons/console`, `waffle-commons/telemetry` and
  `web-auth/webauthn-lib` (`^5.3`) to `require`; declared `../async` and `../telemetry`
  path repositories for local development.
