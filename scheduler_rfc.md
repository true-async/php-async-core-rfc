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
// MyScheduler implements Async\Scheduler (or extends Async\AbstractScheduler).
Async\SchedulerHook::register('my-scheduler', new MyScheduler());
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
namespace Async;

/**
 * Base cancellation exception. Extends \Error on purpose (not \Exception), so a
 * stray catch (\Exception) cannot swallow a cancellation. To cancel a coroutine,
 * resume it with one of these — the throwable is raised at the coroutine's
 * suspension point. There is no separate cancel hook.
 */
class CancellationError extends \Error {}

/**
 * A symmetric execution context. Created via the createCoroutine capability and
 * switched via switchTo (see onLaunch). Opaque to userland — the scheduler owns
 * what a coroutine *is*. Internally it is backed by the engine's Fiber machinery,
 * so it stays compatible with fiber-aware tooling (e.g. Xdebug).
 */
final class Continuation { /* opaque */ }

/**
 * A scheduler implements this interface and hands an instance to
 * SchedulerHook::register(). The `on*` methods are *event callbacks* the engine
 * invokes; the engine performs the actual context switches. Extend
 * AbstractScheduler to override only the hooks you need.
 */
interface Scheduler
{
    /**
     * The scheduler starts. The engine hands over its privileged, otherwise
     * unreachable capabilities — the "mandate" — as plain closures. Because they
     * arrive only here, only the scheduler ever holds them: no other code can
     * switch contexts or mint coroutines.
     */
    public function onLaunch(
        \Closure $switchTo,          // switchTo(Continuation $to, mixed $value = null): mixed
        \Closure $createCoroutine,   // createCoroutine(callable $entry): Continuation
        \Closure $currentCoroutine,  // currentCoroutine(): ?Continuation
    ): void;

    /** A graceful shutdown has been requested. */
    public function onShutdown(): bool;

    /**
     * A foreign Fiber (created by application/third-party code, e.g.
     * Revolt/AMPHP) is starting. Return a Continuation to adopt it onto the
     * coroutine path, or null to leave it low-level.
     */
    public function onFiber(\Fiber $fiber): ?Continuation;

    /** A coroutine became runnable (created, or its wait ended). */
    public function onEnqueue(Continuation $coroutine): bool;

    /**
     * The current flow yields. Pick who runs next and switch to them with the
     * switchTo mandate. `$current` is the coroutine that just yielded.
     */
    public function onSuspend(Continuation $current): void;

    /**
     * Wake a suspended coroutine (deferred: re-queue it). When `$error` is a
     * CancellationError (or any throwable), it is raised at the coroutine's
     * suspension point — that is how cancellation is delivered.
     */
    public function onResume(Continuation $coroutine, ?\Throwable $error = null): bool;

    /** Store a one-shot microtask on the scheduler's queue. */
    public function onDefer(callable $task): bool;

    // --- Coroutine context (queries/providers — not events, so no on-prefix) ---

    /** The coroutine's userland context (string/object keys), or null. */
    public function getContext(Continuation $coroutine): ?object;

    /** The coroutine's internal context (numeric keys, for C extensions), or null. */
    public function getInternalContext(Continuation $coroutine): ?object;

    public function contextFind(object $context, mixed $key): mixed;
    public function contextSet(object $context, mixed $key, mixed $value): bool;
    public function contextUnset(object $context, mixed $key): bool;
}

/** Convenience base: implement only the hooks you need; the rest default. */
abstract class AbstractScheduler implements Scheduler { /* ... */ }

/** Activation point for the concurrent mode. */
final class SchedulerHook
{
    /**
     * Registers a scheduler and activates the concurrent mode. Registered
     * exactly once per process: a second call (by a C extension or by an
     * earlier PHP call) throws an Error.
     */
    public static function register(string $module, Scheduler $scheduler): bool {}

    /** The module name of the registered scheduler, or null when none. */
    public static function getModule(): ?string {}

    /**
     * Queues a callable on the scheduler's microtask queue (one-shot, runs on
     * the next tick). Forwards to the scheduler's onDefer() hook.
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

The **current coroutine** has no setter: it is whatever the `suspend()` hook returns. Because
switching is the scheduler's job — and its implementation need not use fibers at all — the
scheduler is the only party that knows which coroutine is now running, so it reports it through
the return value of `suspend()`, and the core records it as the current coroutine. How that value
is exposed to userland (a `current()` accessor, a coroutine class, `spawn()`/`await()`) is **not
part of this RFC** — like the coroutine object itself, it belongs to the scheduler's API.

The same split applies to **microtasks**: the queue of one-shot callbacks is owned by the
scheduler, not by the engine. `defer()` only forwards the callable to the scheduler's DEFER
hook; storage, draining and the exact semantics are the scheduler's policy.

### Design rationale

**Why an interface (a class), not an array of callables.** An earlier draft
registered a `[hook => callable]` map. An interface is better on every axis: the
implementation is a real object, so the hooks share state through `$this` instead
of a web of `use`-captured variables; the contract is *typed* and checked at
compile time (a wrong signature is an error, not a run-time surprise); `AbstractScheduler`
supplies defaults so a scheduler overrides only what it needs; and the hook set
evolves by adding interface methods, versioned together with the module API. It
also reads the way engine integration points already look (`SessionHandlerInterface`,
`Countable`, …).

**Why the `on*` prefix.** Every method here is a *callback the engine invokes* on
an event — not something the scheduler calls. Naming them `onSuspend`, `onResume`,
`onFiber`, … makes that direction unmistakable and separates them from the
scheduler's own API (`spawn()`, `await()`) and from the query-style methods that
return data (`getContext`, `contextFind`), which keep no prefix precisely because
they are questions the engine asks, not events it announces.

**Why the mandate is passed as hidden closures.** Switching contexts and minting
coroutines are dangerous, privileged operations: if they were globally callable,
any code could jump between coroutines and corrupt the run state. Instead the
engine hands `switchTo` / `createCoroutine` / `currentCoroutine` to `onLaunch()`
as plain closures. They arrive **once**, **only** to the scheduler, and live only
in its private fields — a capability, not ambient authority. No global function,
no static method: nobody but the registered scheduler can ever switch a context.

**Continuation vs Fiber, and why it optimizes switching.** `Fiber` is *asymmetric*:
`resume()`/`suspend()` are coupled, so a fiber can only yield back to whoever
resumed it. To move from coroutine A to coroutine B you must bounce through a
central pump: `A → scheduler → B`, two switches and a round-trip through the loop
for every hop. `Continuation` is *symmetric*: `switchTo(B)` transfers control
**directly** from A to B — one switch, no pump, no intermediary. For workloads
that hand off between coroutines constantly (channels, generators, pipelines) this
halves the number of context switches on the hot path.

**Continuation is Xdebug-compatible.** A `Continuation` is not a parallel,
opaque stack the debugger cannot see: internally it is built on the engine's own
`zend_fiber_context` — the exact primitive the `Fiber` API uses. The symmetric
`switchTo` is just a different *scheduling discipline* over the same underlying
fiber machinery, so step debugging, stack traces, and fiber-aware tooling such as
Xdebug keep working. Symmetric switching buys performance without giving up the
observability the fiber infrastructure already provides.

### Hook specification

Each hook is specified below together with a minimal illustrative implementation. The examples are
methods of a class implementing `Async\Scheduler` whose state is a single run queue
(`$this->queue`). The hooks implement scheduling *policy*; the actual coroutine switches are
performed by the engine.

#### `launch(): ?object`

Invoked once when the scheduler starts. For a C-registered scheduler this happens immediately
before the script code begins executing; for a PHP-registered scheduler it happens at the moment
of registration, since script code is already running. State initialization belongs here. Returns
the coroutine that is now current, or `null` (same contract as `suspend()`).

```php
public function launch(): ?object
{
    $this->queue = new SplQueue();
    return null;
}
```

#### `enqueue(object $coroutine): bool`

A coroutine has become ready for execution, either newly created or with its wait completed. The
implementation places it into the run queue. A return value of `false` indicates the coroutine
was not accepted, for example during shutdown.

```php
public function enqueue(object $coroutine): bool
{
    $this->queue->enqueue($coroutine);
    return true;
}
```

#### `suspend(bool $fromMain, bool $isBailout): ?object`

The central scheduling hook. The currently running flow yields, and the implementation selects
the next coroutine to execute, switches to it, and **returns the coroutine that is now current**
(or `null`) — the core records it. Two parameters describe the *after-main handover*:

- `$fromMain = true`: the main script (or its destructors) has finished, so the remaining
  coroutines are to be run to completion rather than performing a single switch.
- `$isBailout = true`: the main flow terminated abnormally (`exit()`, fatal error), so the
  implementation decides whether the remaining coroutines are completed or cancelled.

```php
public function suspend(bool $fromMain, bool $isBailout): ?object
{
    // Discard remaining work on abnormal termination.
    if ($isBailout) {
        return null;
    }

    $current = null;

    while (!$this->queue->isEmpty()) {
        $current = $this->queue->dequeue();
        $fiber   = $current->fiber;

        // Inside a hook this is a direct switch; control returns here
        // when the fiber yields. A regular yield needs a single switch;
        // after main, drain the queue.
        $fiber->isStarted() ? $fiber->resume() : $fiber->start();

        if (!$fromMain) {
            return $current;
        }
    }

    return $current;   // the coroutine we last switched to
}
```

#### `resume(object $coroutine, ?Throwable $error = null): bool`

A request to wake a suspended coroutine. When `$error` is provided, the throwable is thrown at
the coroutine's suspension point; this is how timeouts and IO failures reach waiting code. A
typical implementation validates the coroutine's state and returns it to the run queue.

```php
public function resume(object $coroutine, ?Throwable $error = null): bool
{
    $coroutine->pendingError = $error;
    $this->queue->enqueue($coroutine);
    return true;
}
```

#### `cancel(object $coroutine, ?Throwable $error = null): bool`

A request to cancel a coroutine. Unlike `resume` with an error, cancellation is a *state*: the
coroutine is marked cancelled, and the mark remains observable after completion.

```php
public function cancel(object $coroutine, ?Throwable $error = null): bool
{
    $coroutine->cancelled = true;
    return $this->resume($coroutine, $error ?? new CancellationError('cancelled'));
}
```

#### `getContext(object $coroutine): ?object` / `getInternalContext(object $coroutine): ?object`

Each coroutine is associated with two *execution-flow contexts*: key/value storage that follows
the logical chain of execution. The **userland** context uses string/object keys (request id,
tracing span, locale); the **internal** context is a separate store reserved for C extensions,
keyed by process-unique numeric keys. These getters return the context object a coroutine is bound
to, which is then operated on with the find/set/unset hooks below.

```php
public function getContext(object $coroutine): ?object
{
    return $coroutine->context ??= new Context();
}
```

#### `contextFind(object $context, mixed $key, bool $includeParent): mixed`

Performs a key lookup in the given userland context. When `$includeParent` is true the lookup
continues along the inheritance chain. Keys are strings or objects (compared by identity).

```php
public function contextFind(object $context, mixed $key, bool $includeParent): mixed
{
    for ($ctx = $context; $ctx !== null; $ctx = $includeParent ? $ctx->parent : null) {
        if ($ctx->values->offsetExists($key)) {
            return $ctx->values[$key];
        }
    }
    return null;
}
```

#### `contextSet(object $context, mixed $key, mixed $value): bool` / `contextUnset(object $context, mixed $key): bool`

Stores or removes a value in the given context. Both operations are strictly local: a child
context cannot modify its parent. The internal context (from `getInternalContext()`) is operated
on by C extensions directly, not through PHP hooks.

```php
public function contextSet(object $context, mixed $key, mixed $value): bool
{
    $context->values[$key] = $value;
    return true;
}

public function contextUnset(object $context, mixed $key): bool
{
    unset($context->values[$key]);
    return true;
}
```

#### `interceptFiber(\Fiber $fiber): ?object`

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

    // Mine stay low-level, everything else gets a coroutine.
    public function interceptFiber(\Fiber $fiber): ?object
    {
        return $this->internalFibers->contains($fiber) ? null : new MyCoroutine($fiber);
    }
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
public function defer(callable $task): bool
{
    $this->tasks->enqueue($task);
    return true;
}
```

#### `shutdown(): bool`

A graceful shutdown has been requested. The implementation stops accepting new work and
determines the fate of the remaining coroutines.

```php
public function shutdown(): bool
{
    while (!$this->queue->isEmpty()) {
        $this->cancel($this->queue->dequeue());
    }
    return true;
}
```

### Responsibilities not registered from PHP

A scheduler also fulfils responsibilities that are part of the C contract but are not exposed as
`Async\SchedulerHook` array entries, because they involve creating opaque engine values or
numeric C keys that a pure-PHP scheduler cannot produce. A C-implemented scheduler provides them
directly:

- **Coroutine creation.** The coroutine object is defined and created by the scheduler; the core
  only records the offset it needs to bridge a coroutine to its object. Nothing about coroutine
  construction is registered from PHP.
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
