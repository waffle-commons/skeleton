# Changelog — waffle-commons/skeleton

All notable changes to this component are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Released in lockstep with the Waffle Commons umbrella tag.

## [0.1.0-beta5] — 2026-06-26

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
