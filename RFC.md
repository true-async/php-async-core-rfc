# PHP RFC: Async Scheduler Hook API

- **Version:** 0.1
- **Date:** 2026-07-02
- **Author:** Edmond, edmondifthen@proton.me
- **Status:** Draft
- **Implementation:** https://github.com/true-async/php-src/tree/async-core
- **Discussion thread:** tbd
- **Voting thread:** tbd

## Introduction

PHP provides no native mechanism for concurrent code execution. Fibers (PHP 8.1) introduced
cooperative context switching, but left scheduling entirely to userland. As a result, each
framework maintains its own event loop, its own coroutine abstraction and its own conventions;
these implementations are mutually incompatible and cannot interoperate with any future
engine-level concurrency.

**The purpose of this RFC is to give PHP the ability to activate a concurrent execution mode.**
The proposal is based on the implementation experience of the
[TrueAsync project](https://github.com/true-async) — a complete concurrency stack for PHP
(scheduler, libuv reactor, thread pool) — and introduces a universal interface: coroutines
become a native engine concept, and the component that drives them — the scheduler — becomes
pluggable. A C extension or a PHP library registers a set of hooks through a single call;
from that point on, PHP operates concurrently.

```php
Async\SchedulerHook::register('my-scheduler', [
    Async\SchedulerHook::ENQUEUE => enqueue(...),   // a coroutine is ready to run
    Async\SchedulerHook::SUSPEND => suspend(...),   // the current flow yields: pick the next
    Async\SchedulerHook::RESUME  => resume(...),    // wake a suspended coroutine
]);
```

## Scope: what this RFC deliberately does not define

This document specifies the *activation contract* and nothing beyond it. **Extensions and
third-party code remain free to define arbitrary functions, classes and APIs on top of the
registered scheduler — `spawn()`, `await()`, channels, futures, an `Async\` namespace — and
this RFC intentionally defines none of them.** The class of the coroutine object, the transfer
of values between coroutines, and the shape of the user-facing API are the exclusive domain of
the scheduler implementation. The [True Async RFC](https://wiki.php.net/rfc/true_async) is one
such API, built on this core.

This separation is deliberate: the engine standardizes *how concurrency is activated and which
component is in charge*, while the ecosystem retains full freedom over *how concurrency is
presented to the user*.

## Goals

1. **A single activation contract.** One registration point removes the need for libraries to
   depend on a specific event-loop implementation.
2. **Schedulers implementable in PHP.** A scheduler may be written in plain PHP — for testing,
   verification and experimentation. The identical hook set, registered from C, serves
   production use.
3. **Strict opt-in.** With no scheduler registered, PHP behaves exactly as it does today, at
   negligible cost.
4. **Better integration for fiber-based libraries.** Because coroutines are represented natively
   and a scheduler can adopt fibers onto the coroutine path (see the `intercept_fiber` hook),
   existing fiber-based libraries — ReactPHP/Revolt, AMPHP — gain a defined way to cooperate
   with the engine instead of each driving concurrency in isolation.

## Proposal

### Coroutines

A coroutine is a lightweight unit of execution: a callable with a defined lifecycle:

> created → queued → running → suspended → finished

Two orthogonal attributes may additionally apply: *cancelled* (cancellation has been requested)
and *main* (the coroutine that wraps the top-level script). Each coroutine records its
completion result or unhandled exception, the source location at which it was spawned, and —
while suspended — a description of what it is waiting for (see the `awaiting_info` hook).

At the PHP level a coroutine is an opaque object. This RFC does not define its class; the
registered scheduler does.

### Registration

```php
final class Async\SchedulerHook
{
    // Hook-name constants used as the array keys (LAUNCH, SHUTDOWN,
    // INTERCEPT_FIBER, ENQUEUE, SUSPEND, RESUME, CANCEL,
    // CONTEXT_FIND, CONTEXT_SET, CONTEXT_UNSET).

    /**
     * Registers a scheduler and activates the concurrent mode.
     *
     * $hooks maps a hook constant to a callable; omitted hooks retain their
     * default implementations. A scheduler is registered exactly once per
     * process: calling this when one is already registered — by a C
     * extension or by an earlier PHP call — throws an Error.
     */
    public static function register(string $module, array $hooks): bool {}

    /** Returns the callable registered for $hook, or null when unset. */
    public static function get(string $hook): ?callable {}
}
```

The hook set is versioned. Future PHP versions may append hooks; a scheduler written against an
earlier set remains functional.

### Hook specification

Each hook is specified below together with a minimal illustrative implementation. The examples
sketch a cooperative scheduler whose state is a single run queue (`$queue = new SplQueue()`).
The hooks implement scheduling *policy*; the actual coroutine switches are performed by the
engine.

#### `launch — fn(): bool`

Invoked once when the scheduler starts. For a C-registered scheduler this occurs immediately
before the script code begins executing; for a PHP-registered scheduler — at the moment of
registration, since script code is already running. State initialization belongs here.

```php
'launch' => function (): bool {
    $GLOBALS['queue'] = new SplQueue();
    return true;
},
```

#### `new_coroutine — fn(): object`

Invoked whenever a coroutine object must be created (for example, when a library spawns a
task). The hook returns the object that represents the coroutine; its class is defined by the
scheduler.

```php
'new_coroutine' => fn (): object => new WorkerCoroutine(),
```

#### `enqueue_coroutine — fn(object $coroutine): bool`

A coroutine has become ready for execution — either newly created or with its wait completed.
The implementation places it into the run queue. A return value of `false` indicates the
coroutine was not accepted (for example, during shutdown).

```php
'enqueue_coroutine' => function (object $coroutine): bool {
    $GLOBALS['queue']->enqueue($coroutine);
    return true;
},
```

#### `suspend — fn(bool $fromMain, bool $isBailout): bool`

The central scheduling hook. The currently running flow yields; the implementation selects the
next coroutine to execute — typically by dequeuing it and requesting the switch from the
engine. Two parameters describe the *after-main handover*:

- `$fromMain = true` — the main script (or its destructors) has finished; the remaining
  coroutines are to be run to completion rather than performing a single switch.
- `$isBailout = true` — the main flow terminated abnormally (`exit()`, fatal error); the
  implementation decides whether the remaining coroutines are completed or cancelled.

```php
'suspend' => function (bool $fromMain, bool $isBailout): bool {
    if ($isBailout) {
        return false;                       // discard remaining work on abnormal termination
    }
    while (!$GLOBALS['queue']->isEmpty()) {
        $next = $GLOBALS['queue']->dequeue();
        // request the engine to continue $next; control returns here when it yields
        if (!$fromMain) {
            return true;                    // regular yield: a single switch suffices
        }
    }
    return true;                            // after main: the queue is fully drained
},
```

#### `resume — fn(object $coroutine, ?Throwable $error): bool`

A request to wake a suspended coroutine. When `$error` is provided, the throwable is thrown at
the coroutine's suspension point; this is the mechanism by which timeouts and IO failures reach
waiting code. A typical implementation validates the coroutine's state and returns it to the
run queue.

```php
'resume' => function (object $coroutine, ?Throwable $error): bool {
    $coroutine->pendingError = $error;
    $GLOBALS['queue']->enqueue($coroutine);
    return true;
},
```

#### `cancel — fn(object $coroutine, ?Throwable $error): bool`

A request to cancel a coroutine. Unlike `resume` with an error, cancellation is a *state*: the
coroutine is marked cancelled, and the mark remains observable after completion.

```php
'cancel' => function (object $coroutine, ?Throwable $error): bool {
    $coroutine->cancelled = true;
    return $this->resume($coroutine, $error ?? new CancellationError('cancelled'));
},
```

#### `awaiting_info — fn(object $coroutine): ?string`

The diagnostics hook. Given a suspended coroutine, the implementation returns a human-readable
description of what the coroutine is waiting for, or `null` when unknown. The engine invokes
this hook wherever concurrency must be explained: introspection tooling, deadlock reports,
debugger output.

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

#### `get_context — fn(?object $coroutine): object`

Each coroutine is associated with an *execution-flow context*: key/value storage that follows
the logical chain of execution (request identifier, tracing span, locale). The hook returns the
context of the given coroutine, creating it lazily; `null` designates the currently running
coroutine. Context objects are opaque; their class and the inheritance model between parent and
child coroutines (sharing, copy-on-write, chained lookup) are defined by the scheduler.

```php
'get_context' => function (?object $coroutine): object {
    $coroutine ??= currentCoroutine();
    return $coroutine->context ??= new Ctx(parent: $coroutine->spawnedBy?->context);
},
```

#### `context_find — fn(object $context, mixed $key): mixed`

Performs a key lookup in the given context. Whether the lookup consults parent contexts is the
scheduler's policy. Keys are strings or objects (compared by identity).

```php
'context_find' => function (object $ctx, mixed $key): mixed {
    for (; $ctx !== null; $ctx = $ctx->parent) {
        if ($ctx->values->offsetExists($key)) {
            return $ctx->values[$key];
        }
    }
    return null;
},
```

#### `context_set — fn(object $context, mixed $key, mixed $value): bool` / `context_unset — fn(object $context, mixed $key): bool`

Stores or removes a value in the given context. Both operations are strictly local: a child
context cannot modify its parent.

```php
'context_set'   => function (object $ctx, mixed $key, mixed $value): bool {
    $ctx->values[$key] = $value;
    return true;
},
'context_unset' => function (object $ctx, mixed $key): bool {
    unset($ctx->values[$key]);
    return true;
},
```

#### `get_internal_context — fn(?object $coroutine): object`

Returns the *internal* context of the given coroutine, creating it lazily; `null` designates
the currently running coroutine. The internal context is a second, separate storage with the
same shape as the userland context, but reserved for C extensions: it is keyed by
process-unique **numeric keys** (an extension allocates its key once from a static name) and
is never visible to PHP code. This guarantees that extension state can neither collide with
userland keys nor leak into scripts.

A scheduler implemented in PHP must still provide this storage — C extensions use it
regardless of who schedules.

```php
'get_internal_context' => function (?object $coroutine): object {
    $coroutine ??= currentCoroutine();
    return $coroutine->internalContext ??= new Ctx(parent: null);
},
```

#### `internal_context_find — fn(object $context, int $key): mixed` / `internal_context_set — fn(object $context, int $key, mixed $value): bool` / `internal_context_unset — fn(object $context, int $key): bool`

Accessors for the internal context: lookup, store and remove by numeric key. The context is
the object returned by `get_internal_context`. Values are destroyed together with the owning
coroutine.

```php
'internal_context_find'  => fn (object $ctx, int $key): mixed => $ctx->values[$key] ?? null,
'internal_context_set'   => function (object $ctx, int $key, mixed $value): bool {
    $ctx->values[$key] = $value;
    return true;
},
'internal_context_unset' => function (object $ctx, int $key): bool {
    unset($ctx->values[$key]);
    return true;
},
```

#### `intercept_fiber — fn(object $fiber): ?bool`

Called by the engine on every `Fiber::start()` while a scheduler is active. It returns `true`
to adopt the fiber onto the coroutine path (its `Fiber::suspend()`/`resume()` route through the
scheduler instead of blocking the thread), `false` to keep the fiber in legacy mode, or `null`
when the hook is not provided — in which case fibers remain legacy.

**Why this hook is necessary.** Existing concurrency frameworks (ReactPHP/Revolt, AMPHP) are
themselves implemented on top of fibers: the fiber is the low-level switching primitive their
event loop drives — suspending to yield to the loop, resuming from its callbacks — while their
user-facing concurrency abstractions are built above it. If the engine adopted every fiber
unconditionally, a scheduler written this way would recurse into itself: the fibers it drives
as part of its own implementation would be turned into coroutines, whose suspension would call
back into the very scheduler that is trying to run them.

`intercept_fiber` resolves this by letting the scheduler decide **per fiber**, because only the
scheduler can tell its own machinery apart from application code. A scheduler keeps references
to the fibers it creates for itself and returns `false` for them, `true` for the rest. No flag
is placed on the fiber and the engine tracks nothing — the distinction lives entirely in the
scheduler:

```php
final class Scheduler
{
    private \SplObjectStorage $internalFibers;   // the fibers I run myself

    private function runInternal(\Closure $fn): void
    {
        $fiber = new \Fiber($fn);
        $this->internalFibers->attach($fiber);   // remember it as mine
        $fiber->start();                          // intercept_fiber returns false -> legacy
    }
}

// registered hook:
'intercept_fiber' => fn (\Fiber $fiber): bool
    => !$this->internalFibers->contains($fiber),  // mine -> legacy, others -> coroutine
```

The precedence is therefore: no scheduler -> legacy; scheduler without the hook -> legacy;
scheduler with the hook -> the hook decides, per fiber.

Because the decision is driven exclusively by the scheduler's own bookkeeping, this design also
guarantees **isolation**: no code outside the scheduler can interfere with how the scheduler
runs its internal fibers. Only fibers the scheduler itself created and recorded are treated as
internal; any fiber originating elsewhere is, by construction, external and therefore subject to
adoption. Third-party or application code cannot mark a fiber as "internal", cannot smuggle a
fiber into the scheduler's private set, and thus cannot alter the scheduling of the scheduler's
own machinery — the engine exposes no such flag, and the set is private to the scheduler
instance.

Unlike the other hooks, `intercept_fiber` receives a real `Fiber` object rather than an opaque
coroutine: the fiber has not been adopted yet at the moment the decision is made.

#### `shutdown — fn(): bool`

A graceful shutdown has been requested. The implementation stops accepting new work and
determines the fate of the remaining coroutines.

```php
'shutdown' => function (): bool {
    while (!$GLOBALS['queue']->isEmpty()) {
        $this->cancel($GLOBALS['queue']->dequeue(), null);
    }
    return true;
},
```

### Engine invocation points

The scheduler is **always active** — there is no lazy initialization and no implicit start on
the first asynchronous call:

- the scheduler **launches before the script code executes** (for a PHP-registered scheduler:
  at the moment of registration);
- when the main script finishes — normally or through `exit()` — the engine invokes
  `suspend(fromMain: true, ...)` so that the remaining coroutines **run to completion**;
- the scheduler receives control once more **after object destructors**; thereafter concurrency
  is terminated for the request.

Consequently, a script that spawns background work and reaches its final statement does not
silently discard that work: the scheduler defines the semantics of the end of the request.

## Backward Incompatible Changes

One class is added: `Async\SchedulerHook`. Code declaring a class with this exact name in the
`Async\` namespace would break; no significant usage is known.

No other observable changes are introduced: with no scheduler registered, PHP behaves exactly
as before.

## Proposed PHP Version(s)

Next minor PHP 8.x.

## RFC Impact

- **To SAPIs:** none observable. CLI, FPM and phpdbg gain the invocation points described
  above; all remain inactive without a registered scheduler.
- **To Existing Extensions:** none by default. Extensions requiring async awareness receive a
  dedicated internal per-coroutine context keyed by process-unique numeric keys, inaccessible
  from PHP code.
- **To the Ecosystem:** a stub for one global function. Event-loop libraries (Revolt, ReactPHP,
  AMPHP, Swoole) obtain a common registration point in place of private, incompatible cores.

## Voting Choices

Yes/no vote, 2/3 majority required: "Accept the Async Scheduler Hook API RFC?"

## Patches and Tests

- Proof of concept: https://github.com/true-async/php-src/tree/async-core
  (core, engine invocation points, phpdbg).
- Scheduler extension: https://github.com/true-async/true-async — the reference C
  implementation of the hooks for this core.
- The PHP registration bridge: to be added to the same branch.

## References

- [True Async RFC](https://wiki.php.net/rfc/true_async) — the complete concurrency model built
  on this core.
- [TrueAsync scheduler extension](https://github.com/true-async/true-async) — the reference
  implementation of the hooks.
- [TrueAsync project](https://github.com/true-async) — the full stack from which this core was
  extracted.
- `Io\Poll` (`main/php_poll.h`) — the readiness-multiplexing API in php-src master, suitable as
  the IO source for a userland event loop.
- [SCHEDULER.md](SCHEDULER.md) — the exact engine invocation points (for implementers).

## Rejected Features

## Changelog
