# php-async-core-rfc
RFC: thin Async Core ABI for PHP (zend_async_API) — mechanism-only coroutine API with a policy-free core

## Documents

- [scheduler_rfc.md](scheduler_rfc.md) — **PHP RFC: Async Scheduler Hook API.** The activation
  contract: how a scheduler registers and drives coroutines (policy vs. mechanism).
- [SCHEDULER.md](SCHEDULER.md) — the exact engine invocation points, for scheduler implementers.
- [reactor.md](reactor.md) — **Reactor C Interface (discussion draft).** A hypothetical
  callback-first `io_*` reactor API — poll, streaming IO, timers, filesystem, cross-thread
  triggers and DNS — and how it feeds the scheduler. Includes an io_uring sketch and a
  hazards analysis. Not an RFC.
