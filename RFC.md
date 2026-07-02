# PHP RFC: Async Core

- **Version:** 0.1
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

**The goal of this RFC is to let PHP activate a concurrent mode.** It builds on the
implementation experience of the [TrueAsync project](https://github.com/true-async) — a complete
concurrency stack for PHP (scheduler, libuv reactor, thread pool) — to propose a universal
interface: coroutines become a native PHP concept, and the logic that drives them — the
scheduler — becomes pluggable. Any C extension or any PHP library registers a set of hooks
through one function, and from that moment PHP is concurrent:

```php
async_scheduler_register('my-scheduler', false, [
    'enqueue_coroutine' => enqueue(...),   // a coroutine is ready to run
    'suspend'           => suspend(...),   // the current code yields: pick who runs next
    'resume'            => resume(...),    // wake a suspended coroutine
]);
```

## What this RFC deliberately does NOT define

This document defines the *activation contract* and nothing above it. **Extensions and
third-party code are free to create any functions, classes or APIs on top of the registered
scheduler — `spawn()`, `await()`, channels, futures, an `Async\` namespace — and this RFC
intentionally defines none of them.** The coroutine object's class, the way values travel
between coroutines, the shape of the user-facing API — all of that is the scheduler's
territory. (The [True Async RFC](https://wiki.php.net/rfc/true_async) is one such API,
built on this core.)

This separation is the point: the engine standardizes *how concurrency is switched on and who
is in charge*, while the ecosystem keeps full freedom in *what it looks like for the user*.

## Goals

1. **One activation contract instead of many frameworks.** With a single registration point,
   "which event loop are you on?" stops being a question a library has to ask.
2. **Concurrency you can hold in your hands.** A scheduler can be written in plain PHP — for
   tests, for teaching, for experiments. The same hook set, registered from C, powers
   production.
3. **Nothing changes until you opt in.** No scheduler registered — PHP behaves exactly as
   today, at effectively zero cost.

## Proposal

### Coroutines

A coroutine is a lightweight unit of execution — a callable with its own lifecycle:

> created → queued → running → suspended → finished

Two more things can be true about it at any point: it was *cancelled*, or it is the *main*
coroutine (your top-level script — yes, it becomes a coroutine too). Every coroutine knows its
result or unhandled exception, where in the code it was spawned, and — if it is suspended —
*what* it is waiting for (see the `awaiting_info` hook below).

In PHP code a coroutine is an opaque object. This RFC does not define its class — the
registered scheduler does.

### Registration

```php
/**
 * Registers a concurrency scheduler and activates the concurrent mode.
 *
 * $hooks maps hook names to callables; omitted hooks keep their defaults.
 * Returns false when a PHP scheduler is already registered and
 * $allowOverride is false.
 *
 * When a C extension has registered the scheduler, calling this function
 * is forbidden and throws an Error: a C scheduler owns concurrency for
 * the whole process, and PHP code cannot replace it.
 */
function async_scheduler_register(string $module, bool $allowOverride, array $hooks): bool {}
```

The hook set is versioned: future PHP versions may append hooks, and a scheduler written
against an older set keeps working.

### The hooks in detail

The examples below sketch a minimal cooperative scheduler holding its state in
`$queue = new SplQueue()`. They show the *policy* each hook implements; the engine performs
the actual coroutine switches.

#### `launch — fn(): bool`

Called once when the scheduler starts. For a C scheduler that happens right before the script
code runs; for a PHP scheduler — immediately at registration (your code is already running).
Initialize your state here.

```php
'launch' => function (): bool {
    $GLOBALS['queue'] = new SplQueue();
    return true;
},
```

#### `new_coroutine — fn(): object`

Called whenever a coroutine object must be created (e.g. a library spawns a task). The hook
returns the object that will represent the coroutine; its class is yours.

```php
'new_coroutine' => fn (): object => new WorkerCoroutine(),
```

#### `enqueue_coroutine — fn(object $coroutine): bool`

A coroutine became ready to run — a fresh one, or one whose wait is over. Put it into your run
queue. Return `false` if you cannot accept it (e.g. shutting down).

```php
'enqueue_coroutine' => function (object $coroutine): bool {
    $GLOBALS['queue']->enqueue($coroutine);
    return true;
},
```

#### `suspend — fn(bool $fromMain, bool $isBailout): bool`

The heart of the scheduler. The currently running code yields, and your policy decides who runs
next — typically by taking the next coroutine from the queue and asking the engine to switch to
it. Two special flags describe the *after-main handover*:

- `$fromMain = true` — the main script (or its destructors) has finished; run the remaining
  coroutines to completion instead of picking just one.
- `$isBailout = true` — the main flow ended abnormally (`exit()`, fatal error); decide whether
  to finish or cancel the rest.

```php
'suspend' => function (bool $fromMain, bool $isBailout): bool {
    if ($isBailout) {
        return false;                       // drop remaining work on fatal errors
    }
    while (!$GLOBALS['queue']->isEmpty()) {
        $next = $GLOBALS['queue']->dequeue();
        // ask the engine to continue $next; returns here when it yields
        if (!$fromMain) {
            return true;                    // normal yield: one switch is enough
        }
    }
    return true;                            // after main: queue fully drained
},
```

#### `resume — fn(object $coroutine, ?Throwable $error): bool`

Someone asks to wake a suspended coroutine. With `$error`, the throwable is thrown at the
coroutine's suspension point — this is how timeouts and IO failures reach the waiting code.
Typical implementation: validate the state and hand the coroutine back to the queue.

```php
'resume' => function (object $coroutine, ?Throwable $error): bool {
    $coroutine->pendingError = $error;
    $GLOBALS['queue']->enqueue($coroutine);
    return true;
},
```

#### `cancel — fn(object $coroutine, ?Throwable $error, bool $safely): bool`

Cancellation was requested. Unlike a plain `resume` with an error, cancellation is a *state*:
the coroutine is marked cancelled, and the mark stays visible after it finishes. With
`$safely = true` the delivery must be deferred until the coroutine reaches a point where
cancellation is allowed.

```php
'cancel' => function (object $coroutine, ?Throwable $error, bool $safely): bool {
    $coroutine->cancelled = true;
    return $this->resume($coroutine, $error ?? new CancellationError('cancelled'));
},
```

#### `awaiting_info — fn(object $coroutine): ?string`

The diagnostics hook: given a suspended coroutine, return a human-readable description of what
it is waiting for — or `null` if unknown. The engine calls it when concurrency needs to be
*explained*: introspection tools, deadlock reports, debugger output.

```php
'awaiting_info' => function (object $coroutine): ?string {
    return match ($coroutine->waitKind) {
        'timer'  => "sleeping until {$coroutine->deadline}",
        'socket' => "socket #{$coroutine->fd} (readable)",
        default  => null,
    };
},
// "coroutine #12, spawned at worker.php:40 — socket #7 (readable)"
```

#### `shutdown — fn(): bool`

Graceful shutdown was requested. Stop accepting work, decide the fate of what remains.

```php
'shutdown' => function (): bool {
    while (!$GLOBALS['queue']->isEmpty()) {
        $this->cancel($GLOBALS['queue']->dequeue(), null, false);
    }
    return true;
},
```

### When the engine calls you

The scheduler is **always on** — no lazy initialization, no "first async call starts the loop"
magic:

- it **launches before your code runs** (PHP-registered: immediately at registration);
- when the main script finishes — normally or via `exit()` — `suspend(fromMain: true, ...)` is
  invoked so the remaining coroutines can **run to completion**;
- it is invoked once more **after object destructors**, then concurrency is over for the
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

1. `$hooks` as an array of callables vs. positional callable parameters.

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

## Changelog
