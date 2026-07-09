# PHP Async Core RFC

**A thin, policy-free concurrency core for PHP.** The PHP engine standardises one thing: how a
scheduler is activated and which component is in charge. Everything user-facing (coroutine
classes, `spawn()`/`await()`, channels, event loops) stays with the scheduler and the
ecosystem.

```php
// The whole activation surface: one call.
Async\SchedulerHook::register('my-scheduler', new MyScheduler());
```

With no scheduler registered, PHP behaves exactly as it does today.

## Documents

| Document | What is inside |
|---|---|
| **[scheduler_rfc.md](https://github.com/true-async/php-async-core-rfc/blob/main/scheduler_rfc.md)** | **The RFC: Async Scheduler Hook API.** The activation contract, the hook set, a minimal scheduler, worked examples. Start here. |
| [SCHEDULER.md](https://github.com/true-async/php-async-core-rfc/blob/main/SCHEDULER.md) | The exact engine invocation points, for scheduler implementers. |
| [scheduler_rfc_examples.md](https://github.com/true-async/php-async-core-rfc/blob/main/scheduler_rfc_examples.md) | Worked examples with real code: per-coroutine contexts (`ob_start()`, `gethostbyname()`) and the microtask-driven concurrent iterator. |
| [reactor.md](https://github.com/true-async/php-async-core-rfc/blob/main/reactor.md) | Reactor C interface, a discussion draft: a callback-first `io_*` API (poll, streaming IO, timers, filesystem, DNS) and how it feeds the scheduler. Not an RFC. |
| [example/](https://github.com/true-async/php-async-core-rfc/tree/main/example) | Runnable schedulers: the same cooperative scheduler on plain Fibers and on Continuations. |

## Implementation

- Proof of concept: [php/php-src#22561](https://github.com/php/php-src/pull/22561),
  branch [`async-core`](https://github.com/true-async/php-src/tree/async-core): the core,
  the engine invocation points, the PHP registration bridge and its tests.
- Reference C scheduler: the [TrueAsync extension](https://github.com/true-async/true-async).
- The full stack the core was extracted from: the
  [TrueAsync project](https://github.com/true-async).
