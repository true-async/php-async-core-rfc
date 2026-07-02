# PHP RFC: Async Core ABI

- **Version:** 0.4
- **Date:** 2026-07-02
- **Author:** Edmond, edmondifthen@proton.me
- **Status:** Draft
- **Implementation:** https://github.com/true-async/php-src/tree/async-core
- **Discussion thread:** tbd
- **Voting thread:** tbd

## Introduction

PHP has no native way to execute code concurrently. The [True Async RFC](https://wiki.php.net/rfc/true_async)
proposes a complete concurrency model; this RFC extracts its **minimal foundation** and proposes
only that.

The essence of the proposal: **any C extension — or plain PHP code — can define a set of handlers
through a single interface and take control of concurrency in PHP.** The engine itself gains no
scheduler, no event loop and no classes; it gains a coroutine representation and well-defined
points where control is handed to whoever registered.

```php
async_scheduler_register('my-scheduler', false, [
    'enqueue_coroutine' => fn (object $coroutine) => $queue->push($coroutine),
    'suspend'           => fn (bool $fromMain, bool $isBailout) => $loop->next(),
    'resume'            => fn (object $coroutine, ?Throwable $e) => $loop->switchTo($coroutine, $e),
    // unset handlers keep their default stubs
]);
```

## Goals

1. **Mechanism, not policy.** The engine knows what a coroutine is and when to hand over
   control. It does not know how to schedule.
2. **One interface, two audiences.** The same set of handlers is registered either by a C
   extension (production) or by PHP code (prototyping, testing, education). The PHP surface
   mirrors the internal one: it is a set of functions, not classes.
3. **Zero cost when unused.** Without a registered scheduler PHP executes exactly as today.

## Proposal

PHP gains a minimal concurrency foundation consisting of three ideas.

### 1. The coroutine

A coroutine is a lightweight unit of execution with a defined lifecycle:

> created → queued → running → suspended → finished

plus two orthogonal markers: *cancelled* (cancellation was requested) and *main* (the coroutine
wrapping the top-level script). A coroutine carries its entry point, its completion result or
exception, the location it was spawned from, and an optional diagnostics hook that answers
"what is this coroutine waiting for?" — the building block for introspection and deadlock
reporting.

The engine does **not** define how a coroutine waits. Wait-state storage, run queues, IO
readiness, timers — all of that belongs to the registered scheduler.

In PHP, coroutines appear as opaque objects. The core declares no classes; the concrete class
is supplied by the registered scheduler.

### 2. The single registration interface

A scheduler registers a set of named handlers. The set is identical for both audiences:

| Handler | Meaning |
|---|---|
| `new_coroutine` | create a coroutine object |
| `enqueue_coroutine` | a coroutine became ready: accept it for execution |
| `suspend` | the current flow yields; decide who runs next |
| `resume` | wake a suspended coroutine, optionally delivering an error |
| `cancel` | request cancellation of a coroutine |
| `launch` | the scheduler starts |
| `shutdown` | graceful shutdown was requested |

A C extension registers the handlers at module init through the internal registration call.
PHP code registers them with the mirroring function proposed by this RFC:

```php
/**
 * $handlers maps handler names to callables; unset handlers keep their defaults.
 * Returns false if a scheduler is already registered and $allowOverride is false.
 * A C-extension scheduler always takes precedence over PHP handlers.
 */
function async_scheduler_register(string $module, bool $allowOverride, array $handlers): bool {}
```

Handler signatures at the PHP level:

| Key | Callable signature |
|---|---|
| `new_coroutine` | `fn(): object` |
| `enqueue_coroutine` | `fn(object $coroutine): bool` |
| `suspend` | `fn(bool $fromMain, bool $isBailout): bool` |
| `resume` | `fn(object $coroutine, ?Throwable $error): bool` |
| `cancel` | `fn(object $coroutine, ?Throwable $error, bool $safely): bool` |
| `launch` | `fn(): bool` |
| `shutdown` | `fn(): bool` |

Registration is versioned and forward-compatible: future handlers are appended, and a scheduler
built against an older set keeps working. Unregistered handlers fail with a clear error when
invoked, so the whole facility is inert until someone registers.

For a PHP-registered scheduler, `launch` is invoked immediately upon registration (the engine's
own launch point has already passed by the time userland code runs).

### 3. Engine handover points

The scheduler is **not lazy** — it always starts, right before the script code runs. After the
main script finishes (normally, via `exit()`/fatal error, and once more after object
destructors), the engine hands control back to the scheduler so the remaining coroutines can
run to completion. The exact integration points are documented in [SCHEDULER.md](SCHEDULER.md).

This replaces the lazy-initialization checks scattered across IO code paths in earlier designs:
by the time any IO happens, the scheduler is already running.

## Backward Incompatible Changes

One new function in the global namespace: `async_scheduler_register()`. Code declaring a
function with this exact name would break; no significant usage is known.

No engine behaviour changes without a registered scheduler.

## Proposed PHP Version(s)

Next minor PHP 8.x.

## RFC Impact

- **To SAPIs:** two guarded, no-op-by-default handover calls per request; phpdbg gains the same
  pair.
- **To Existing Extensions:** none by default. Extensions that want async awareness use the
  internal interface; a dedicated internal per-coroutine storage keeps their state invisible to
  userland.
- **To the Ecosystem:** a stub for one global function.

## Open Issues

1. `$handlers` as an array of callables vs. positional callable parameters.
2. Should `async_scheduler_register()` from PHP be allowed to override a C provider when
   `$allowOverride = true`, or should the C registration always win?

## Future Scope

- **Mirror functions for the remaining handlers and state** (`async_new_coroutine()`,
  `async_suspend()`, `async_resume()`, `async_current_coroutine()`, coroutine field accessors) —
  deferred until the core proves itself.
- An object-oriented API (`Async\` namespace) — the
  [True Async RFC](https://wiki.php.net/rfc/true_async) territory, built by a provider on top
  of this core.
- A reactor provider over the `Io\Poll` API already in master.
- Structured concurrency, channels, futures — separate RFCs on top of this core.

## Voting Choices

Yes/no vote, 2/3 majority required: "Accept the Async Core ABI RFC?"

## Patches and Tests

- Proof of concept: https://github.com/true-async/php-src/tree/async-core
  (core header/implementation, engine integration, phpdbg).
- The PHP registration bridge: to be added to the same branch.

## References

- [True Async RFC](https://wiki.php.net/rfc/true_async) — the full concurrency model this core
  is extracted from.
- [TrueAsync reference implementation](https://github.com/true-async) — the complete stack
  (scheduler, libuv reactor, thread pool) this core was distilled from.
- `Io\Poll` (`main/php_poll.h`) — the readiness-multiplexing API in php-src master that a
  userland event loop builds on.

## Rejected Features

- **Classes in the core.** The internal interface is a set of handlers; the PHP surface is a
  set of functions. Coroutine values are opaque objects whose classes are provider-defined.
- **PHP mirrors for the execution-flow context.** The context is consumed through the
  provider's own API; the core exposes no PHP-level context functions.
- **Event system in the core** (awaitables, callback vectors, triggers): provider territory.
  The core keeps only the "what is being awaited" diagnostics hook.
- **Embedded wait state (waker):** storage is provider-defined.
- **Lazy scheduler initialization:** the scheduler always starts before the script code.

## Changelog

- 0.4 (2026-07-02): Proposal rewritten concept-first, all C code removed from the document.
- 0.3 (2026-07-02): the PHP API is scoped down to `async_scheduler_register()` only; context
  slots get no PHP mirrors at all.
- 0.2 (2026-07-02): removed all classes; the PHP API is a function-level mirror of the ABI.
- 0.1 (2026-07-02): initial draft.
