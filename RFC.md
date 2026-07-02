# PHP RFC: Async Core

- **Version:** 0.5
- **Date:** 2026-07-02
- **Author:** Edmond, edmondifthen@proton.me
- **Status:** Draft
- **Implementation:** https://github.com/true-async/php-src/tree/async-core
- **Discussion thread:** tbd
- **Voting thread:** tbd

## Introduction

PHP cannot run code concurrently. Fibers (PHP 8.1) gave us cooperative switching, but every
framework had to invent its own event loop, its own coroutine type and its own rules — and none
of them can cooperate with each other or with future engine-level concurrency.

This RFC proposes the missing common ground: **coroutines become a native PHP concept, and the
logic that drives them — the scheduler — becomes pluggable.** Any C extension or any PHP library
can register a set of handlers through one function and take control of concurrency:

```php
async_scheduler_register('my-scheduler', false, [
    'enqueue_coroutine' => enqueue(...),   // a coroutine is ready to run
    'suspend'           => suspend(...),   // the current code yields: pick who runs next
    'resume'            => resume(...),    // wake a suspended coroutine
]);
```

PHP itself does not ship a scheduler, an event loop or any new classes. It ships the *contract*:
what a coroutine is, which handlers exist, and when they are called. Whether the implementation
behind that contract is a high-performance C extension or thirty lines of PHP for a unit test —
the rest of the code cannot tell the difference.

## Goals

1. **One contract instead of many frameworks.** Today ReactPHP, AMPHP, Swoole and Revolt each
   define their own incompatible core. With a single registration point, "which event loop are
   you on?" stops being a question a library has to ask.
2. **Concurrency you can hold in your hands.** A scheduler can be written in plain PHP —
   for tests, for teaching, for experiments. The same handler set, registered from C, powers
   production.
3. **Nothing changes until you opt in.** No scheduler registered — PHP behaves exactly as today,
   at effectively zero cost.

## Proposal

### Coroutines

A coroutine is a lightweight unit of execution — a callable with its own lifecycle:

> created → queued → running → suspended → finished

Two more things can be true about it at any point: it was *cancelled*, or it is the *main*
coroutine (your top-level script — yes, it becomes a coroutine too).

Every coroutine knows its result or unhandled exception, where in the code it was spawned, and —
if it is waiting — *what* it is waiting for, as a human-readable description. That last part is
the foundation for debugging concurrent code: "coroutine #12, spawned at worker.php:40, waiting
on socket 7 (readable)".

In PHP code a coroutine is an opaque object. This RFC does not define its class — the registered
scheduler does. (The object-oriented API — `Async\Coroutine` and friends — is the subject of the
[True Async RFC](https://wiki.php.net/rfc/true_async), which builds on this core.)

### One function: register your scheduler

```php
/**
 * Registers a concurrency scheduler.
 *
 * $handlers maps handler names to callables; omitted handlers keep their
 * defaults. Returns false when a scheduler is already registered and
 * $allowOverride is false. A scheduler registered by a C extension always
 * takes precedence over one registered from PHP.
 */
function async_scheduler_register(string $module, bool $allowOverride, array $handlers): bool {}
```

The handlers — identical whether registered from C or from PHP:

| Handler | Called when... | Signature |
|---|---|---|
| `new_coroutine` | a coroutine object must be created | `fn(): object` |
| `enqueue_coroutine` | a coroutine became ready to run | `fn(object $coroutine): bool` |
| `suspend` | the running code yields; decide who runs next | `fn(bool $fromMain, bool $isBailout): bool` |
| `resume` | a suspended coroutine must wake up, optionally with an error thrown at its suspension point | `fn(object $coroutine, ?Throwable $error): bool` |
| `cancel` | cancellation of a coroutine was requested | `fn(object $coroutine, ?Throwable $error, bool $safely): bool` |
| `launch` | the scheduler starts | `fn(): bool` |
| `shutdown` | graceful shutdown was requested | `fn(): bool` |

The handler set is versioned: future PHP versions may append handlers, and a scheduler written
against an older set keeps working.

### When the engine calls you

The scheduler is **always on** — no lazy initialization, no "first async call starts the loop"
magic:

- it **launches before your code runs** (for a PHP-registered scheduler: immediately at
  registration, since your code is already running);
- when the main script finishes — normally or via `exit()` — the scheduler gets control to
  **finish the remaining coroutines**;
- it gets control once more **after object destructors**, then concurrency is over for the
  request.

So a script that spawns background work and reaches its last line does not silently drop that
work — the scheduler decides what "the end of the request" means.

## Backward Incompatible Changes

One new function in the global namespace: `async_scheduler_register()`. Code declaring a
function with this exact name would break; no significant usage is known.

Nothing else changes: without a registered scheduler PHP behaves exactly as before.

## Proposed PHP Version(s)

Next minor PHP 8.x.

## RFC Impact

- **To SAPIs:** none observable. CLI, FPM and phpdbg gain the handover points described above,
  inactive without a scheduler.
- **To Existing Extensions:** none by default. Extensions that want to be async-aware get a
  dedicated internal per-coroutine storage, invisible to PHP code.
- **To the Ecosystem:** a stub for one global function. Event-loop libraries (Revolt, ReactPHP,
  AMPHP, Swoole) gain a common registration point instead of N private cores.

## Open Issues

1. `$handlers` as an array of callables vs. positional callable parameters.
2. Should a PHP-registered scheduler be allowed to override a C one when
   `$allowOverride = true`, or should C always win?

## Future Scope

- **Functions to drive coroutines directly** (`async_new_coroutine()`, `async_suspend()`,
  `async_resume()`, `async_current_coroutine()`, coroutine state accessors) — deferred until
  the core proves itself.
- **The object-oriented API** (`Async\` namespace: `Coroutine`, `spawn()`, `await()`) — the
  [True Async RFC](https://wiki.php.net/rfc/true_async), built on this core.
- **A built-in reactor** over the `Io\Poll` API already in master — so a production event loop
  ships with PHP.
- Structured concurrency, channels, futures — separate RFCs on top of this core.

## Voting Choices

Yes/no vote, 2/3 majority required: "Accept the Async Core RFC?"

## Patches and Tests

- Proof of concept: https://github.com/true-async/php-src/tree/async-core
  (core, engine handover points, phpdbg).
- The PHP registration bridge: to be added to the same branch.

## References

- [True Async RFC](https://wiki.php.net/rfc/true_async) — the full concurrency model built on
  this core.
- [TrueAsync reference implementation](https://github.com/true-async) — the complete stack
  (scheduler, libuv reactor, thread pool) this core was distilled from.
- `Io\Poll` (`main/php_poll.h`) — the readiness-multiplexing API in php-src master that a
  userland event loop builds on.
- [SCHEDULER.md](SCHEDULER.md) — exact engine handover points (for implementers).

## Rejected Features

- **New classes.** The contract is a set of handlers; the PHP surface is a set of functions.
  Coroutine objects' classes are scheduler-defined; the OO API belongs to True Async.
- **PHP access to the execution-flow context.** Per-coroutine context storage exists in the
  contract but is consumed through the scheduler's own API.
- **An event system in the core** (awaitables, subscriptions, triggers): scheduler territory.
  The core keeps only the "what is being awaited" diagnostics hook.
- **Lazy scheduler initialization:** the scheduler always starts before your code.

## Changelog

- 0.5 (2026-07-02): rewritten for the PHP-programmer audience.
- 0.4 (2026-07-02): Proposal rewritten concept-first, all C code removed.
- 0.3 (2026-07-02): PHP API scoped down to `async_scheduler_register()` only.
- 0.2 (2026-07-02): removed all classes; function-level mirror of the internal contract.
- 0.1 (2026-07-02): initial draft.
