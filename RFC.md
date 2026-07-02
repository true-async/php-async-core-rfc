# PHP RFC: Async Core ABI

- **Version:** 0.2
- **Date:** 2026-07-02
- **Author:** Edmond, edmondifthen@proton.me
- **Status:** Draft
- **Implementation:** https://github.com/true-async/php-src/tree/async-core
- **Discussion thread:** tbd
- **Voting thread:** tbd

## Introduction

PHP has no native way to execute code concurrently. The [True Async RFC](https://wiki.php.net/rfc/true_async)
proposes a complete concurrency model; this RFC extracts its **minimal foundation**: a thin,
policy-free coroutine core inside the Zend engine, and a small set of PHP functions that mirror
it one-to-one.

The core contains **no scheduler, no reactor, no event system and no classes**. It defines what a
coroutine *is* (a data structure with a lifecycle) and *where* the engine hands control over.
Everything else — run queues, IO readiness, timers — is supplied by a registered scheduler:
a C extension in production, or plain PHP callables for prototyping and testing.

```php
async_scheduler_register('my-scheduler', false, [
    'enqueue_coroutine' => fn (object $coroutine) => $queue->push($coroutine),
    'suspend'           => fn (bool $fromMain, bool $isBailout) => $loop->next(),
    'resume'            => fn (object $coroutine, ?Throwable $e) => $loop->switchTo($coroutine, $e),
    // unset handlers keep their default stubs
]);

$coro = async_new_coroutine(function () {
    echo "Hello, ";
    async_suspend();
    echo "World!\n";
});
async_enqueue_coroutine($coro);
```

## Goals

1. **Mechanism, not policy.** The engine knows how to represent a coroutine and when to hand
   over control. It does not know how to schedule.
2. **The PHP API mirrors the ABI.** Every PHP function corresponds to exactly one ABI slot,
   macro or global. Nothing exists at the PHP level that does not exist at the C level.
   No classes: the ABI is a set of function pointers, so the PHP surface is a set of functions.
3. **Zero cost when unused.** Without a registered scheduler PHP executes exactly as today.

## Proposal

### The core ABI (C level)

`Zend/zend_async_API.h` (~350 lines) defines:

- **`zend_coroutine_t`** — the coroutine structure: a packed lifecycle status
  (`created → queued → running → suspended → finished`), modifier flags (`cancelled`, `main`),
  the entry point, the completion result/exception, the spawn location, and an `awaiting_info`
  diagnostics hook ("what is this coroutine waiting for?"). There is no embedded wait state:
  how a coroutine waits is the scheduler's business.
- **Scheduler slots** — function pointers registered through a versioned structure
  (`zend_async_scheduler_register()`): `new_coroutine`, `enqueue_coroutine`, `suspend`, `resume`,
  `cancel`, `launch`, `shutdown`, `get_class_ce`, `call_on_main_stack`, plus opaque
  execution-flow context accessors (`get_context`, `get_internal_context`, `context_find/set/unset`).
  Unfilled slots keep default stubs that fail with a clear error.
- **Engine integration** — the scheduler is *not lazy*: it launches right before the script code
  runs, and receives control again after the main flow ends (including the bailout path and after
  destructors). See [SCHEDULER.md](SCHEDULER.md) for the exact integration points.

Low-level details live in the implementation branch and are intentionally out of scope here.

### The PHP API (mirror of the ABI)

A thin C bridge layer exposes the ABI to userland. Coroutines and contexts appear as **opaque
objects**: the core declares no classes — the concrete class is supplied by the registered
scheduler (this is what the `get_class_ce` slot expresses at the C level).

#### Registration

```php
/**
 * Mirror of zend_async_scheduler_register().
 * $handlers maps slot names to callables; unset slots keep their defaults.
 * Returns false if a scheduler is registered and $allowOverride is false.
 * A C-extension scheduler always takes precedence over PHP handlers.
 */
function async_scheduler_register(string $module, bool $allowOverride, array $handlers): bool {}
```

Recognized `$handlers` keys and their signatures (identical to the ABI slots):

| Key | Callable signature | ABI slot |
|---|---|---|
| `new_coroutine` | `fn(): object` | `new_coroutine` |
| `enqueue_coroutine` | `fn(object $coroutine): bool` | `enqueue_coroutine` |
| `suspend` | `fn(bool $fromMain, bool $isBailout): bool` | `suspend` |
| `resume` | `fn(object $coroutine, ?Throwable $error): bool` | `resume` |
| `cancel` | `fn(object $coroutine, ?Throwable $error, bool $safely): bool` | `cancel` |
| `launch` | `fn(): bool` | `launch` |
| `shutdown` | `fn(): bool` | `shutdown` |

For a PHP-registered scheduler, `launch` is invoked immediately upon registration (the engine's
own launch point has already passed by the time userland code runs).

#### Functions

| PHP function | Mirrors (ABI) |
|---|---|
| `async_new_coroutine(callable $task, mixed ...$args): object` | `new_coroutine` slot + `fcall` |
| `async_enqueue_coroutine(object $coroutine): bool` | `enqueue_coroutine` slot |
| `async_suspend(): bool` | `suspend` slot (`from_main = false`) |
| `async_resume(object $coroutine, ?Throwable $error = null): bool` | `resume` slot |
| `async_cancel(object $coroutine, ?Throwable $error = null, bool $safely = false): bool` | `cancel` slot |
| `async_current_coroutine(): ?object` | `ZEND_ASYNC_CURRENT_COROUTINE` |
| `async_active_coroutine_count(): int` | `ZEND_ASYNC_ACTIVE_COROUTINE_COUNT` |
| `async_is_enabled(): bool` | `zend_async_is_enabled()` |
| `async_coroutine_status(object $coroutine): int` | packed status (constants below) |
| `async_coroutine_is_cancelled(object $coroutine): bool` | `ZEND_COROUTINE_IS_CANCELLED` |
| `async_coroutine_result(object $coroutine): mixed` | `coroutine->result` |
| `async_coroutine_exception(object $coroutine): ?Throwable` | `coroutine->exception` |
| `async_coroutine_awaiting_info(object $coroutine): ?string` | `awaiting_info` hook |
| `async_coroutine_spawn_location(object $coroutine): string` | `filename` / `lineno` |
| `async_get_context(?object $coroutine = null): object` | `get_context` slot (NULL = current) |
| `async_context_find(object $context, mixed $key, bool $includeParent = true): mixed` | `context_find` slot |
| `async_context_set(object $context, mixed $key, mixed $value): bool` | `context_set` slot |
| `async_context_unset(object $context, mixed $key): bool` | `context_unset` slot |

Status constants mirror the packed enum:
`ASYNC_COROUTINE_CREATED`, `ASYNC_COROUTINE_QUEUED`, `ASYNC_COROUTINE_RUNNING`,
`ASYNC_COROUTINE_SUSPENDED`, `ASYNC_COROUTINE_FINISHED`.

Not mirrored (pure C mechanics with no PHP expression): `get_class_ce` (class registry),
`call_on_main_stack` (OS-thread stack), `get_internal_context` (reserved for C extensions by
definition), the `transfer_error` ownership flag.

### Design rule

> A new ABI slot gets a mirroring PHP function with the same signature.
> If it cannot be expressed in PHP, it is internal mechanics and gets nothing.

This keeps the two surfaces from ever diverging: the RFC for any future slot is simultaneously
the RFC for its PHP function.

## Backward Incompatible Changes

New functions are added to the global namespace with the `async_` prefix. Code declaring
functions with these exact names would break; a GitHub code search shows no significant usage.

No engine behaviour changes without a registered scheduler.

## Proposed PHP Version(s)

Next minor PHP 8.x.

## RFC Impact

- **To SAPIs:** two guarded, no-op-by-default calls per request in `main.c`; phpdbg gains the
  same pair.
- **To Existing Extensions:** none by default. Extensions that want async awareness use the ABI
  slots; the internal context gives them per-coroutine state invisible to userland.
- **To the Ecosystem:** stubs for ~18 global functions and 5 constants.

## Open Issues

1. `$handlers` as an array of callables vs. positional callable parameters.
2. Should `async_scheduler_register()` from PHP be allowed to override a C provider when
   `$allowOverride = true`, or should the C registration always win?
3. Behaviour of `async_suspend()` with no scheduler registered: throw `\Error` (proposed) or
   return `false`.

## Future Scope

- An object-oriented API (`Async\` namespace: `Coroutine`, `Scheduler`, `Context` classes) —
  the [True Async RFC](https://wiki.php.net/rfc/true_async) territory, built by a provider on
  top of this core.
- A reactor provider over the `Io\Poll` API (`main/php_poll.h`) already in master.
- Structured concurrency, channels, futures — separate RFCs on top of this core.

## Voting Choices

Yes/no vote, 2/3 majority required: "Accept the Async Core ABI RFC?"

## Patches and Tests

- Proof of concept: https://github.com/true-async/php-src/tree/async-core
  (`Zend/zend_async_API.h` / `.c`, engine integration in `main/main.c`, `sapi/phpdbg`).
- The PHP function bridge layer: to be added to the same branch.

## References

- [True Async RFC](https://wiki.php.net/rfc/true_async) — the full concurrency model this core
  is extracted from.
- [TrueAsync reference implementation](https://github.com/true-async) — ABI v0.22 with a
  scheduler, libuv reactor and thread pool.
- `main/php_poll.h` — the readiness-multiplexing API in php-src master that a userland event
  loop builds on.

## Rejected Features

- **Classes in the core.** The ABI is a set of function pointers; the PHP surface is a set of
  functions. Coroutine/context values are opaque objects whose classes are provider-defined.
- **Event system in the core** (awaitable base struct, callback vectors, triggers): provider
  territory. The core keeps only the `awaiting_info` diagnostics hook.
- **Embedded waker:** wait-state storage is provider-defined.
- **Lazy scheduler initialization:** the scheduler always starts before the script code.

## Changelog

- 0.2 (2026-07-02): removed all classes; the PHP API is a function-level mirror of the ABI.
- 0.1 (2026-07-02): initial draft.
