# PHP RFC: Async Scheduler Hook API

- **Version:** 0.1
- **Date:** 2026-07-02
- **Author:** Edmond, edmondifthen@proton.me
- **Status:** Draft
- **Implementation:** https://github.com/php/php-src/pull/22561
- **Discussion thread:** tbd
- **Voting thread:** tbd

## Introduction

PHP provides no native mechanism for concurrent code execution. Fibers (PHP 8.1) introduced
cooperative context switching, but left scheduling entirely to userland. As a result, each
framework maintains its own event loop, its own coroutine abstraction and its own conventions.
These implementations are mutually incompatible, and none of them can interoperate with any
future engine-level concurrency.

**The purpose of this RFC is to give PHP the ability to activate a concurrent execution mode.**
The proposal draws on the implementation experience of the
[TrueAsync project](https://github.com/true-async), a complete concurrency stack for PHP
(scheduler, libuv reactor, thread pool). It introduces a universal interface: coroutines become
a native engine concept, and the component that drives them, the scheduler, becomes pluggable.
A C extension or a PHP library registers a set of hooks through a single call, and from that
point on PHP operates concurrently.

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
registered scheduler** (`spawn()`, `await()`, channels, futures, an `Async\` namespace), **and
this RFC intentionally defines none of them.** The class of the coroutine object, the transfer
of values between coroutines, and the shape of the user-facing API are the exclusive domain of
the scheduler implementation. The [True Async RFC](https://wiki.php.net/rfc/true_async) is one
such API, built on this core.

This separation is deliberate. The engine standardizes *how concurrency is activated and which
component is in charge*, while the ecosystem retains full freedom over *how concurrency is
presented to the user*.

## Goals

1. **A single activation contract.** One registration point removes the need for libraries to
   depend on a specific event-loop implementation.
2. **Schedulers implementable in PHP.** A scheduler may be written in plain PHP, for testing,
   verification and experimentation. The identical hook set, registered from C, serves
   production use.
3. **Strict opt-in.** With no scheduler registered, PHP behaves exactly as it does today, at
   negligible cost.
4. **Better integration for fiber-based libraries.** Because coroutines are represented natively
   and a scheduler can adopt fibers onto the coroutine path (see the `intercept_fiber` hook),
   existing fiber-based libraries such as ReactPHP/Revolt and AMPHP gain a defined way to
   cooperate with the engine instead of each driving concurrency in isolation.

## Proposal

### Coroutines

A coroutine is a lightweight unit of execution: a callable with a defined lifecycle.

> created → queued → running → suspended → finished

Two orthogonal attributes may additionally apply: *cancelled* (cancellation has been requested)
and *main* (the coroutine that wraps the top-level script). Each coroutine records its
completion result or unhandled exception, the source location at which it was spawned, and, while
suspended, a description of what it is waiting for (see the `awaiting_info` hook).

At the PHP level a coroutine is an opaque object. This RFC does not define its class; the
registered scheduler does.

### Registration

```php
final class Async\SchedulerHook
{
    // Hook-name constants, used as the keys of the $hooks array.
    public const string LAUNCH          = 'launch';
    public const string SHUTDOWN        = 'shutdown';
    public const string INTERCEPT_FIBER = 'intercept_fiber';
    public const string ENQUEUE         = 'enqueue_coroutine';
    public const string SUSPEND         = 'suspend';
    public const string RESUME          = 'resume';
    public const string CANCEL          = 'cancel';
    public const string CONTEXT_FIND    = 'context_find';
    public const string CONTEXT_SET     = 'context_set';
    public const string CONTEXT_UNSET   = 'context_unset';
    public const string DEFER           = 'defer';

    /**
     * Registers a scheduler and activates the concurrent mode.
     *
     * $hooks maps a hook constant to a callable; omitted hooks retain their
     * default implementations. A scheduler is registered exactly once per
     * process: calling this when one is already registered (by a C extension
     * or by an earlier PHP call) throws an Error.
     */
    public static function register(string $module, array $hooks): bool {}

    /** The module name of the registered scheduler, or null when none. */
    public static function getModule(): ?string {}

    /**
     * Queues a callable on the scheduler's microtask queue (one-shot,
     * runs on the next tick). Forwards to the DEFER hook: the queue and
     * its draining belong to the scheduler.
     */
    public static function defer(callable $task): void {}

}
```

The hook set is not versioned separately: it evolves together with the standard PHP module API
(`ZEND_MODULE_API_NO`), so there is no independent async/scheduler ABI number to maintain. Future
PHP versions may append hooks, and a scheduler built against an earlier module API remains
functional.

The division of labour is strict: the hooks decide *which* coroutine runs next (policy), while
the engine performs the switch (mechanism). There is no switching API: the scheduler uses the
plain Fiber interface. Inside scheduler code (a hook invocation), `start()`/`resume()`/`throw()`
on a fiber bound to a coroutine performs the direct context switch and returns when the fiber
yields; in application code the same calls park the value and hand over to the scheduler through
the hooks. A complete scheduler loop is therefore: dequeue, `$fiber->resume()`, repeat. Nothing
switchable is reachable from application code.

The same split applies to **microtasks**: the queue of one-shot callbacks is owned by the
scheduler, not by the engine. `defer()` only forwards the callable to the scheduler's DEFER
hook; storage, draining and the exact semantics are the scheduler's policy.

### Hook specification

Each hook is specified below together with a minimal illustrative implementation. The examples
sketch a cooperative scheduler whose state is a single run queue (`$queue = new SplQueue()`).
The hooks implement scheduling *policy*; the actual coroutine switches are performed by the
engine.

#### `launch(): bool`

Invoked once when the scheduler starts. For a C-registered scheduler this happens immediately
before the script code begins executing; for a PHP-registered scheduler it happens at the moment
of registration, since script code is already running. State initialization belongs here.

```php
Async\SchedulerHook::LAUNCH => function (): bool {
    $GLOBALS['queue'] = new SplQueue();
    return true;
},
```

#### `enqueue_coroutine(object $coroutine): bool`

A coroutine has become ready for execution, either newly created or with its wait completed. The
implementation places it into the run queue. A return value of `false` indicates the coroutine
was not accepted, for example during shutdown.

```php
Async\SchedulerHook::ENQUEUE => function (object $coroutine): bool {
    $GLOBALS['queue']->enqueue($coroutine);
    return true;
},
```

#### `suspend(bool $fromMain, bool $isBailout): bool`

The central scheduling hook. The currently running flow yields, and the implementation selects
the next coroutine to execute, typically by dequeuing it and requesting the switch from the
engine. Two parameters describe the *after-main handover*:

- `$fromMain = true`: the main script (or its destructors) has finished, so the remaining
  coroutines are to be run to completion rather than performing a single switch.
- `$isBailout = true`: the main flow terminated abnormally (`exit()`, fatal error), so the
  implementation decides whether the remaining coroutines are completed or cancelled.

```php
Async\SchedulerHook::SUSPEND => function (bool $fromMain, bool $isBailout): bool {
    // Discard remaining work on abnormal termination.
    if ($isBailout) {
        return false;
    }

    while (!$GLOBALS['queue']->isEmpty()) {
        $fiber = $GLOBALS['queue']->dequeue()->fiber;

        // Inside a hook this is a direct switch; control returns here
        // when the fiber yields. A regular yield needs a single switch;
        // after main, drain the queue.
        $fiber->isStarted() ? $fiber->resume() : $fiber->start();

        if (!$fromMain) {
            return true;
        }
    }

    return true;
},
```

#### `resume(object $coroutine, ?Throwable $error): bool`

A request to wake a suspended coroutine. When `$error` is provided, the throwable is thrown at
the coroutine's suspension point; this is how timeouts and IO failures reach waiting code. A
typical implementation validates the coroutine's state and returns it to the run queue.

```php
Async\SchedulerHook::RESUME => function (object $coroutine, ?Throwable $error): bool {
    $coroutine->pendingError = $error;
    $GLOBALS['queue']->enqueue($coroutine);
    return true;
},
```

#### `cancel(object $coroutine, ?Throwable $error): bool`

A request to cancel a coroutine. Unlike `resume` with an error, cancellation is a *state*: the
coroutine is marked cancelled, and the mark remains observable after completion.

```php
Async\SchedulerHook::CANCEL => function (object $coroutine, ?Throwable $error): bool {
    $coroutine->cancelled = true;
    return $this->resume($coroutine, $error ?? new CancellationError('cancelled'));
},
```

#### `context_find(object $context, mixed $key): mixed`

Each coroutine is associated with an *execution-flow context*: key/value storage that follows the
logical chain of execution (request identifier, tracing span, locale). `context_find` performs a
key lookup in the given context. Whether the lookup consults parent contexts is the scheduler's
policy. Keys are strings or objects (compared by identity). The context object itself is produced
by the scheduler (see *Responsibilities not registered from PHP* below).

```php
Async\SchedulerHook::CONTEXT_FIND => function (object $ctx, mixed $key): mixed {
    for (; $ctx !== null; $ctx = $ctx->parent) {
        if ($ctx->values->offsetExists($key)) {
            return $ctx->values[$key];
        }
    }
    return null;
},
```

#### `context_set(object $context, mixed $key, mixed $value): bool` / `context_unset(object $context, mixed $key): bool`

Stores or removes a value in the given context. Both operations are strictly local: a child
context cannot modify its parent.

```php
Async\SchedulerHook::CONTEXT_SET => function (object $ctx, mixed $key, mixed $value): bool {
    $ctx->values[$key] = $value;
    return true;
},
Async\SchedulerHook::CONTEXT_UNSET => function (object $ctx, mixed $key): bool {
    unset($ctx->values[$key]);
    return true;
},
```

#### `intercept_fiber(object $fiber): ?object`

The point where the engine links a fiber to a coroutine. There are two kinds of fibers:
*low-level* ones, pure context switching that Revolt-style loops drive themselves (no coroutine
involved), and *high-level* ones, a fiber bound to a coroutine and driven by the scheduler.

Called by the engine on every `Fiber::start()` while a scheduler is active, the hook decides
which kind this fiber is. It returns **the coroutine to bind to the fiber**, created by the
scheduler, and the fiber then runs on the coroutine path: its `Fiber::suspend()`/`resume()`
route through the scheduler instead of blocking the thread. Returning `null` keeps the fiber on
the low-level path. When the hook is not provided, every fiber stays low-level.

**Why this hook is necessary.** Existing concurrency frameworks (ReactPHP/Revolt, AMPHP) are
themselves implemented on top of fibers. The fiber is the low-level switching primitive their
event loop drives, suspending to yield to the loop and resuming from its callbacks, while their
user-facing concurrency abstractions are built above it. If the engine adopted every fiber
unconditionally, a scheduler written this way would recurse into itself: the fibers it drives as
part of its own implementation would be turned into coroutines, whose suspension would call back
into the very scheduler that is trying to run them.

`intercept_fiber` resolves this by letting the scheduler decide **per fiber**, because only the
scheduler can tell its own machinery apart from application code. A scheduler keeps references to
the fibers it creates for itself and returns `null` for them, a fresh coroutine for the rest. No
flag is placed on the fiber and the engine tracks nothing; the distinction lives entirely in the
scheduler:

```php
final class Scheduler
{
    // The fibers the scheduler runs itself.
    private \SplObjectStorage $internalFibers;

    private function runInternal(\Closure $fn): void
    {
        $fiber = new \Fiber($fn);

        // Remember it as mine, then start it: intercept_fiber returns null,
        // so it stays on the low-level path.
        $this->internalFibers->attach($fiber);
        $fiber->start();
    }
}

// Registered hook: mine stay low-level, everything else gets a coroutine.
Async\SchedulerHook::INTERCEPT_FIBER => fn (\Fiber $fiber): ?object
    => $this->internalFibers->contains($fiber) ? null : new MyCoroutine($fiber),
```

The precedence follows from this. With no scheduler, fibers stay low-level. With a scheduler but
no hook, they stay low-level. With the hook, it decides per fiber.

Because the decision is driven exclusively by the scheduler's own bookkeeping, this design also
guarantees **isolation**: no code outside the scheduler can interfere with how the scheduler runs
its internal fibers. Only fibers the scheduler itself created and recorded are treated as
internal; any fiber originating elsewhere is, by construction, external and therefore subject to
adoption. Third-party or application code cannot mark a fiber as "internal", cannot smuggle a
fiber into the scheduler's private set, and thus cannot alter the scheduling of the scheduler's
own machinery. The engine exposes no such flag, and the set is private to the scheduler instance.

Unlike the other hooks, `intercept_fiber` receives a real `Fiber` object rather than an opaque
coroutine, because the fiber has not been adopted yet at the moment the decision is made.

#### `defer(callable $task): bool`

Queues a one-shot task on the scheduler's microtask queue; the scheduler runs it on its next
tick. The engine never stores tasks itself: both `SchedulerHook::defer()` and C-level consumers
route through this hook, and the queue lives entirely in the scheduler.

```php
Async\SchedulerHook::DEFER => function (callable $task) use ($tasks): bool {
    $tasks->enqueue($task);
    return true;
},
```

#### `shutdown(): bool`

A graceful shutdown has been requested. The implementation stops accepting new work and
determines the fate of the remaining coroutines.

```php
Async\SchedulerHook::SHUTDOWN => function (): bool {
    while (!$GLOBALS['queue']->isEmpty()) {
        $this->cancel($GLOBALS['queue']->dequeue(), null);
    }
    return true;
},
```

### Responsibilities not registered from PHP

A scheduler also fulfils responsibilities that are part of the C contract but are not exposed as
`Async\SchedulerHook` array entries, because they involve creating opaque engine values or
numeric C keys that a pure-PHP scheduler cannot produce. A C-implemented scheduler provides them
directly:

- **Coroutine creation.** The coroutine object is defined and created by the scheduler; the core
  only records the offset it needs to bridge a coroutine to its object. Nothing about coroutine
  construction is registered from PHP.
- **Context retrieval** (`get_context`, `get_internal_context`). These return the opaque context
  a coroutine is bound to, creating it lazily. The userland context uses string/object keys; the
  *internal* context is a separate storage reserved for C extensions, keyed by process-unique
  numeric keys and never visible to PHP, so extension state can neither collide with userland
  keys nor leak into scripts. Only the userland lookup/mutation hooks (`CONTEXT_FIND`,
  `CONTEXT_SET`, `CONTEXT_UNSET`) are registered from PHP.
- **Wait diagnostics** (`awaiting_info`). Whoever suspends a coroutine may attach a handler that
  returns a human-readable description of what it is waiting for (`"socket #7 (readable)"`), used
  by introspection tooling and deadlock reports. It is a per-coroutine handler, not a scheduler
  registration.
- **Destructor-phase interception** (`gc_destructors`). The around-interceptor for the garbage
  collector's destructor phase: when the GC reaches the point where destructors of collected
  cycles must run, it can call this instead of executing the phase directly, bracket it (open a
  completion group, run, await everything the destructors spawned, including transitive
  descendants) and only then let collection proceed. **This is reserved for C extensions and is
  not available to PHP-land, by nature of *when* it runs.** The destructor phase fires at the
  very latest stage of the request — during and after the teardown of global variables and the
  object store — when userland is already being dismantled and no PHP-registered scheduler can be
  safely re-entered. Only C code lives at that stage, so only a C-implemented scheduler (or the
  engine itself) may hook it; a pure-PHP scheduler cannot. The engine keeps every correctness
  guarantee regardless: destructors are invoked by the engine executor (each exactly once), and
  after the interceptor returns the engine re-runs the executor as a safety net, so a broken or
  absent hook cannot prevent destructors from being called. Without it, the classic synchronous
  destructor path runs unchanged, and userland `__destruct` always takes that classic path.

### Engine invocation points

The scheduler is **always active**. There is no lazy initialization and no implicit start on the
first asynchronous call:

- the scheduler **launches before the script code executes** (for a PHP-registered scheduler, at
  the moment of registration);
- when the main script finishes, normally or through `exit()`, the engine invokes
  `suspend(fromMain: true, ...)` so that the remaining coroutines **run to completion**;
- the scheduler receives control once more **after object destructors**, after which concurrency
  is terminated for the request.

Consequently, a script that spawns background work and reaches its final statement does not
silently discard that work: the scheduler defines the semantics of the end of the request.

## Backward Incompatible Changes

One class is added: `Async\SchedulerHook`. Code declaring a class with this exact name in the
`Async\` namespace would break; no significant usage is known.

No other observable changes are introduced. With no scheduler registered, PHP behaves exactly as
before.

## Proposed PHP Version(s)

Next minor PHP 8.x.

## RFC Impact

- **To SAPIs:** none observable. CLI, FPM and phpdbg gain the invocation points described above,
  all inactive without a registered scheduler.
- **To Existing Extensions:** none by default. Extensions requiring async awareness receive a
  dedicated internal per-coroutine context keyed by process-unique numeric keys, inaccessible
  from PHP code.
- **To the Ecosystem:** a stub for one class. Event-loop libraries (Revolt, ReactPHP, AMPHP,
  Swoole) obtain a common registration point in place of private, incompatible cores.

## Voting Choices

Yes/no vote, 2/3 majority required: "Accept the Async Scheduler Hook API RFC?"

## Patches and Tests

- Proof of concept: https://github.com/true-async/php-src/tree/async-core
  (core, engine invocation points, phpdbg, the PHP registration bridge and its tests).
- Scheduler extension: https://github.com/true-async/true-async, the reference C implementation
  of the hooks for this core.

## References

- [True Async RFC](https://wiki.php.net/rfc/true_async): the complete concurrency model built on
  this core.
- [TrueAsync scheduler extension](https://github.com/true-async/true-async): the reference
  implementation of the hooks.
- [TrueAsync project](https://github.com/true-async): the full stack from which this core was
  extracted.
- `Io\Poll` (`main/php_poll.h`): the readiness-multiplexing API in php-src master, suitable as the
  IO source for a userland event loop.
- [SCHEDULER.md](SCHEDULER.md): the exact engine invocation points, for implementers.

## Rejected Features

## Changelog
