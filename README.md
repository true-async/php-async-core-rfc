# PHP Async Core RFC

**A thin, policy-free concurrency core for PHP.** The PHP engine standardizes one thing: how a
scheduler is activated and which component is in charge. Everything user-facing (coroutine
classes, `spawn()`/`await()`, channels, event loops) stays with the scheduler and the
ecosystem.

With no scheduler registered, PHP behaves exactly as it does today.

## Documents

| Document | What is inside |
|---|---|
| **[scheduler_rfc.md](https://github.com/true-async/php-async-core-rfc/blob/main/scheduler_rfc.md)** | **PHP RFC: Concurrency Support in the PHP Engine.** The notifications, the granted operations, per-coroutine state and the request lifecycle changes. Start here. |
| [SCHEDULER.md](https://github.com/true-async/php-async-core-rfc/blob/main/SCHEDULER.md) | The C-level interface and the exact engine invocation points, for scheduler implementers. |
| [core-integration.md](https://github.com/true-async/php-async-core-rfc/blob/main/core-integration.md) | Every place the integration touches php-src, file by file. |
| [scheduler_rfc_examples.md](https://github.com/true-async/php-async-core-rfc/blob/main/scheduler_rfc_examples.md) | Worked examples with real code: per-coroutine contexts (`ob_start()`, `gethostbyname()`) and the microtask-driven concurrent iterator. |
| [reactor.md](https://github.com/true-async/php-async-core-rfc/blob/main/reactor.md) | Reactor C interface, a discussion draft: a callback-first `io_*` API (poll, streaming IO, timers, filesystem, DNS) and how it feeds the scheduler. Not an RFC. |

## Implementation

- Proof of concept: [php/php-src#22561](https://github.com/php/php-src/pull/22561),
  branch [`async-core`](https://github.com/true-async/php-src/tree/async-core): the engine
  capabilities and the lifecycle points.
- Reference C scheduler:
  [ext/test_scheduler](https://github.com/true-async/php-src/tree/async-core/ext/test_scheduler),
  in the same tree.
- Scheduler bridge for PHP:
  [ext-scheduler-hook](https://github.com/true-async/ext-scheduler-hook), a separate extension,
  not part of the RFC.
- Reference production scheduler: [true-async](https://github.com/true-async/true-async).
- The full stack the core was extracted from: the
  [TrueAsync project](https://github.com/true-async).
