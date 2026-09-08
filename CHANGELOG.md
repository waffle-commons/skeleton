# Changelog — waffle-commons/skeleton

All notable changes to this component are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Released in lockstep with the Waffle Commons umbrella tag.

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
