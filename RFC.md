# PHP RFC: Async Core ABI

- **Version:** 0.1
- **Date:** 2026-07-02
- **Author:** Edmond, edmondifthen@proton.me
- **Status:** Draft
- **Implementation:** https://github.com/true-async/php-src/tree/async-core
- **Discussion thread:** tbd
- **Voting thread:** tbd

## Introduction

PHP has no native way to execute code concurrently. The [True Async RFC](https://wiki.php.net/rfc/true_async)
proposes a complete concurrency model; this RFC extracts its **minimal foundation** and proposes
only that: a *thin, policy-free coroutine core* inside the Zend engine, plus a PHP API that lets a
scheduler — implemented either as a C extension **or in pure PHP** — plug into it.

The core deliberately contains **no scheduler, no reactor, no event system**. It defines what a
coroutine *is* (a data structure with a lifecycle) and *where* the engine hands control over
(function-pointer slots and two engine integration points). Everything else — run queues, IO
readiness, timers, channels — is provided by a registered implementation.

```php
$scheduler = new Async\SimpleScheduler();
Async\Scheduler::register($scheduler);

$coro = $scheduler->spawn(function (string $who) {
    echo "Hello, ";
    Async\Coroutine::suspend();      // give control back to the scheduler
    echo "$who!\n";
    return 42;
}, 'World');

$scheduler->run();                   // Hello, World!
var_dump($coro->getResult());        // int(42)
```

## Goals

1. **Mechanism, not policy.** The engine knows how to represent a coroutine and when to hand over
   control. It does not know how to schedule.
2. **Two extension levels.** A production scheduler is a C extension filling the ABI slots.
   For experimentation, education and testing, the *same* slots can be driven from userland
   through the `Async\Scheduler` registration API.
3. **A ninefold smaller ABI.** The reference TrueAsync ABI header is ~3000 lines; this core is
   ~350. Every removed line is one that can no longer break ABI compatibility.
4. **Zero cost when unused.** Without a registered scheduler, PHP executes exactly as today.

## Proposal

The proposal consists of three layers.

### 1. C ABI: `Zend/zend_async_API.h`

#### Coroutine structure

```c
struct _zend_coroutine_s {
    /* bits 0-3: lifecycle status; bits 4+: modifier flags */
    uint32_t flags;
    zend_fcall_t *fcall;                       /* userland entry, NULL for internal */
    zend_coroutine_entry_t internal_entry;     /* C entry, NULL for userland */
    void *extended_data;                       /* scheduler-owned */
    zval result;
    zend_object *exception;
    zend_string *filename;                     /* spawn location */
    uint32_t lineno;
    zend_coroutine_awaiting_info_fn awaiting_info;   /* diagnostics hook */
    zend_async_coroutine_dispose extended_dispose;
};
```

The lifecycle is a packed enum — `created → queued → running → suspended → finished` — the single
source of truth behind the PHP-level `is*()` methods. There is **no embedded wait state**: how a
coroutine waits is the scheduler's business. Instead, whoever suspends a coroutine may attach an
`awaiting_info` handler returning a human-readable description of the wait ("poll: socket 12,
readable"), which powers introspection and deadlock reports.

#### Scheduler slots

A provider registers a versioned struct of function pointers:

```c
typedef struct _zend_async_scheduler_api_s {
    uint32_t version;     /* ABI version the provider was built against */
    size_t size;          /* sizeof() at provider build time — forward compatible */

    zend_async_new_coroutine_t     new_coroutine;
    zend_async_enqueue_coroutine_t enqueue_coroutine;
    zend_async_suspend_t           suspend;      /* (from_main, is_bailout) */
    zend_async_resume_t            resume;       /* (coroutine, error, transfer) */
    zend_async_cancel_t            cancel;
    zend_async_scheduler_launch_t  launch;
    zend_async_shutdown_t          shutdown;
    zend_async_get_class_ce_t      get_class_ce;
    zend_async_call_on_main_stack_t call_on_main_stack;
    zend_async_get_context_t       get_context;
    zend_async_get_context_t       get_internal_context;
    zend_async_context_find_t      context_find;
    zend_async_context_set_t       context_set;
    zend_async_context_unset_t     context_unset;
} zend_async_scheduler_api_t;

ZEND_API bool zend_async_scheduler_register(
        const char *module, bool allow_override, const zend_async_scheduler_api_t *api);
```

New slots are appended at the end only; the `size` field lets the core accept providers compiled
against an older struct. Unregistered slots fail with a clear error, so the API is inert until a
provider appears.

The execution-flow context (`zend_async_context_t`) is fully opaque: storage, inheritance and
lifetime live in the provider. `get_context(NULL)` means "the current coroutine";
`get_internal_context` returns a separate storage reserved for C extensions, invisible to PHP code.

#### Engine integration points

The scheduler is **not lazy** — it always starts right before the script code runs:

| Location | Hook |
|---|---|
| `php_execute_script_ex()`, before scripts | `ZEND_ASYNC_SCHEDULER_LAUNCH()` when READY |
| `php_execute_script_ex()`, after scripts | `ZEND_ASYNC_RUN_SCHEDULER_AFTER_MAIN(false)` |
| `php_execute_script_ex()`, bailout path | `ZEND_ASYNC_RUN_SCHEDULER_AFTER_MAIN(true)` |
| `php_request_shutdown()`, after destructors | `ZEND_ASYNC_RUN_SCHEDULER_AFTER_MAIN(false)` + deactivate |
| phpdbg, around `zend_execute()` | same pair |

See [SCHEDULER.md](SCHEDULER.md) for the full table and lifecycle.

### 2. PHP API: coroutines

```php
namespace Async;

final class Coroutine
{
    public function isStarted(): bool {}
    public function isQueued(): bool {}
    public function isRunning(): bool {}
    public function isSuspended(): bool {}
    public function isFinished(): bool {}
    public function isCancelled(): bool {}

    public function getResult(): mixed {}          // throws if not finished
    public function getException(): ?\Throwable {}
    public function getAwaitingInfo(): ?string {}  // awaiting_info hook
    public function getSpawnLocation(): string {}

    /** Yield control; returns the value passed to resume(). */
    public static function suspend(mixed $value = null): mixed {}

    public static function getCurrent(): ?Coroutine {}
}
```

`Coroutine::suspend()` is **symmetric**: control returns to whoever called `resume()`. The engine
does not know what a "scheduler" is — a scheduler is simply the code that calls `resume()`.

### 3. PHP API: scheduler registration

This is the userland mirror of `zend_async_scheduler_register()`. A PHP class implementing
`SchedulerInterface` can be registered as the active provider; the C slots are then bridged to its
methods.

```php
namespace Async;

interface SchedulerInterface
{
    /** A coroutine became ready: put it into your run queue. */
    public function enqueue(Coroutine $coroutine): void;

    /**
     * The engine hands over control.
     * $fromMain = true: the main flow (or its destructors) has finished —
     * drain the remaining coroutines. $isBailout reports abnormal termination.
     */
    public function handover(bool $fromMain, bool $isBailout): void;

    /** The request is starting: initialize your state. */
    public function launch(): void;

    /** Graceful shutdown was requested. */
    public function shutdown(): void;
}

final class Scheduler
{
    /** Register a userland scheduler. Throws if one is already active
     *  and $allowOverride is false. */
    public static function register(
        SchedulerInterface $scheduler, bool $allowOverride = false): void {}

    public static function isRegistered(): bool {}

    /** Create a coroutine (status: created). */
    public static function spawn(callable $task, mixed ...$args): Coroutine {}

    /** Wake a suspended coroutine, delivering a value or an error. */
    public static function resume(Coroutine $coroutine, mixed $value = null): void {}
    public static function throw(Coroutine $coroutine, \Throwable $error): void {}
    public static function cancel(Coroutine $coroutine, ?\Throwable $error = null): void {}

    /** Number of live (not finished) coroutines. */
    public static function count(): int {}
    public static function hasPending(): bool {}
}
```

Division of labour: `Scheduler::spawn/resume/throw/cancel` are the *mechanism* (they map directly
to the C slots and perform the actual context switch); `SchedulerInterface` receives the *policy*
callbacks (which coroutine runs next, when to poll IO). A minimal round-robin scheduler in pure
PHP is ~30 lines; combined with the `Io\Poll` API already in master (`Io\Poll\Context::wait()`),
a full event loop can be written in userland with no C code.

A C extension that registers through `zend_async_scheduler_register()` takes precedence and makes
the PHP-level registration throw — production deployments use the C path; the PHP path exists for
prototyping, testing and teaching.

### Exceptions

```php
namespace Async;

class AsyncError extends \Error {}          // misuse: resume a finished coroutine, etc.
class CancellationError extends \Error {}   // delivered into cancelled coroutines
```

## Backward Incompatible Changes

None. The `Async\` namespace is new; the engine changes are guarded no-ops without a registered
provider.

## Proposed PHP Version(s)

Next minor PHP 8.x.

## RFC Impact

- **To SAPIs:** two guarded calls per request in `main.c`; phpdbg gains the same pair. No
  behavioural change without a provider.
- **To Existing Extensions:** none by default. Extensions that want async awareness use the slots
  and macros; the `get_internal_context` storage gives them per-coroutine state without touching
  userland data.
- **To the Ecosystem:** static analyzers need stubs for the `Async\` namespace only.

## Open Issues

1. Should `SchedulerInterface::handover()` be split into `afterMain()` / `afterDestructors()`?
2. Semantics of `Coroutine::suspend()` on the main coroutine without a registered scheduler:
   throw `AsyncError` (proposed) or no-op?
3. Should the PHP-level registration be allowed to *replace* a C provider when
   `$allowOverride = true`, or should C always win?

## Future Scope

- Reactor layer over the `Io\Poll` API (`main/php_poll.h`) as a C provider.
- Structured concurrency (scopes), channels, futures — as separate RFCs on top of this core.
- `awaiting_info` aggregation into a deadlock report.

## Voting Choices

Yes/no vote, 2/3 majority required: "Accept the Async Core ABI RFC?"

## Patches and Tests

- Proof of concept: https://github.com/true-async/php-src/tree/async-core
  (`Zend/zend_async_API.h` / `.c`, engine integration in `main/main.c`, `sapi/phpdbg`).

## References

- [True Async RFC](https://wiki.php.net/rfc/true_async) — the full concurrency model this core
  is extracted from.
- [TrueAsync reference implementation](https://github.com/true-async) — ABI v0.22 with scheduler,
  libuv reactor, thread pool.
- `main/php_poll.h` — the readiness-multiplexing API already in php-src master that a userland
  event loop builds on.

## Rejected Features

- **Event system in the core** (awaitable base struct, callback vectors, triggers): moved entirely
  to the provider. The core keeps only the `awaiting_info` diagnostics hook.
- **Embedded waker:** wait-state storage is provider-defined.
- **Exception helper functions:** consumers use `zend_throw_exception()` with the class registry.
- **Lazy scheduler initialization** (`ZEND_ASYNC_SCHEDULER_INIT` scattered across IO paths):
  the scheduler always starts before the script code.

## Changelog

- 0.1 (2026-07-02): initial draft.
