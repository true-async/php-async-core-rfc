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
// MyScheduler implements Async\Scheduler.
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
 * resume it with one of these; the throwable is raised at the coroutine's
 * suspension point. There is no separate cancel hook.
 */
class CancellationError extends \Error {}

/**
 * The low-level symmetric-switch primitive: a bare execution context. Minted via
 * the createContinuation capability and entered via switchTo (see onLaunch); it
 * is *not* the schedulable unit; a scheduler wraps a Continuation into its own
 * coroutine object. Internally backed by the engine's Fiber machinery, so it
 * stays compatible with fiber-aware tooling (e.g. Xdebug).
 */
final class Continuation { /* opaque */ }

/**
 * A scheduler implements this interface and hands an instance to
 * SchedulerHook::register(). The `on*` methods are *event callbacks* the engine
 * invokes; the engine performs the actual context switches.
 *
 * Two layers. A `Continuation` is the low-level symmetric-switch primitive:
 * only `switchTo` and `createContinuation` touch it. A *coroutine* is the
 * schedulable unit the scheduler builds on top of a Continuation; the RFC does
 * not type it (it is the scheduler's own object). enqueue/suspend/context and
 * the current-coroutine accessor all speak in coroutines, not continuations.
 */
interface Scheduler
{
    /**
     * The scheduler starts. The engine hands over its privileged, otherwise
     * unreachable capabilities (the "mandate") as plain closures. Because they
     * arrive only here, only the scheduler ever holds them: no other code can
     * switch contexts or mint continuations. `currentCoroutine` returns the
     * coroutine the engine currently records as running, ready to be handed to
     * onEnqueue().
     */
    public function onLaunch(
        \Closure $switchTo,            // switchTo(Continuation $to, mixed $value = null): mixed
        \Closure $createContinuation,  // createContinuation(callable $entry): Continuation
        \Closure $currentCoroutine,    // currentCoroutine(): ?object
    ): void;

    /** A graceful shutdown has been requested. */
    public function onShutdown(): bool;

    /**
     * A foreign Fiber (created by application/third-party code, e.g.
     * Revolt/AMPHP) is starting. Return a coroutine to adopt it onto the
     * schedule, or null to leave it a plain low-level fiber.
     */
    public function onFiber(\Fiber $fiber): ?object;

    /**
     * Make a coroutine runnable: the single "schedule it" operation (a fresh
     * enqueue and a resume are the same thing). A non-null `$error` (typically a
     * CancellationError) is raised at the coroutine's suspension point; that is
     * how cancellation and IO/timeout failures reach waiting code.
     */
    public function onEnqueue(object $coroutine, ?\Throwable $error = null): bool;

    /**
     * The current coroutine yields. Pick who runs next and switch to it with the
     * switchTo mandate; `$coroutine` is the one that just yielded. Return the
     * coroutine now running; the engine records it as the current coroutine
     * (this is the only way the current coroutine is set).
     */
    public function onSuspend(object $coroutine): ?object;

    /** Store a one-shot microtask on the scheduler's queue. */
    public function onDefer(callable $task): bool;

    // --- Coroutine context (queries/providers, not events, so no on-prefix) ---

    /** The coroutine's userland context (string/object keys). */
    public function getContext(object $coroutine): object;

    /** The coroutine's internal context (numeric keys, for C extensions). */
    public function getInternalContext(object $coroutine): object;

    public function contextFind(object $context, mixed $key): mixed;
    public function contextSet(object $context, mixed $key, mixed $value): bool;
    public function contextUnset(object $context, mixed $key): bool;
}

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
the engine performs the switch (mechanism). A scheduler drives its own coroutines through the
`switchTo` mandate handed to `onLaunch()`, and adopted fibers (see `onFiber`) through the plain
`Fiber` interface. Nothing switchable is reachable from application code.

The **current coroutine** has no setter: it is whatever `onSuspend()` returns. The scheduler is
the only party that knows which coroutine is now running, so it reports it through that return
value, the engine records it, and hands it back through the `currentCoroutine` mandate. How the
coroutine is exposed to userland (a `current()` accessor, a coroutine class, `spawn()`/`await()`)
is **not part of this RFC**: like the coroutine object itself, it belongs to the scheduler's API.

The same split applies to **microtasks**: the one-shot callback queue is owned by the scheduler,
not the engine. `defer()` only forwards the callable to `onDefer()`; storage, draining, and exact
semantics are the scheduler's policy.

### Design rationale

- **An interface, not an array of callables.** A real object shares state through
  `$this`, is type-checked at compile time, and grows by adding methods, the way
  `SessionHandlerInterface` and other engine integration points already work.

- **The mandate as hidden closures.** `switchTo` and `createContinuation` are handed
  to `onLaunch()` once, only to the scheduler: a capability, not a global function
  or static method that any code could call.

- **Continuation vs Fiber.** A `Fiber` is asymmetric (yields only to its resumer),
  so A → B costs two switches through an intermediary. A `Continuation` is
  symmetric: `switchTo(B)` goes A → B directly, halving the switches on hot paths
  (channels, generators, pipelines).

- **Xdebug-compatible.** A `Continuation` is built on the same `zend_fiber_context`
  the `Fiber` API uses, so step debugging and stack traces keep working.

### Hook specification

The hooks are the methods of `Async\Scheduler`. A hook the scheduler does not implement keeps the
engine's default behaviour. The hooks decide *which* coroutine runs next (policy); the engine
performs the switch (mechanism).

#### `onLaunch(Closure $switchTo, Closure $createContinuation, Closure $currentCoroutine): void`

Called once when the scheduler starts: for a C scheduler just before the script runs, for a PHP
scheduler at registration (script code is already running). The engine hands over the mandate as
closures: `switchTo(Continuation $to, mixed $value = null)` enters a continuation,
`createContinuation(callable): Continuation` mints one, `currentCoroutine(): ?object` returns the
coroutine the engine records as running. State initialisation belongs here.

#### `onEnqueue(object $coroutine, ?Throwable $error = null): bool`

Make a coroutine runnable and place it in the run queue. Enqueuing a fresh coroutine and resuming
a suspended one are the same operation. A non-null `$error` (typically a `CancellationError`) is
raised at the coroutine's suspension point, which is how cancellation and IO/timeout failures
reach waiting code. `false` means the coroutine was not accepted (for example during shutdown).

#### `onSuspend(object $coroutine): ?object`

The central scheduling hook: the current coroutine yields. The scheduler selects the next runnable
coroutine, enters it with `switchTo`, and returns the coroutine now running; the engine records it
as the current coroutine. Once the run queue drains after the main script has finished, the
scheduler runs the remaining coroutines to completion.

#### `onFiber(Fiber $fiber): ?object`

The point where the engine offers a starting `Fiber` for adoption. Called on every
`Fiber::start()` while a scheduler is active. Return a coroutine to bind to the fiber (its
`suspend()`/`resume()` then route through the scheduler); return `null` to leave it a plain
low-level fiber. When the hook is absent, every fiber stays low-level.

This matters because existing frameworks (ReactPHP/Revolt, AMPHP) are themselves built on fibers:
the fiber is the low-level primitive their own event loop drives. If the engine adopted every
fiber, such a scheduler would recurse into itself. So the scheduler decides per fiber, keeping
references to the fibers it created for itself and returning `null` for those, a coroutine for the
rest. The engine places no flag on the fiber and tracks nothing; the private set lives in the
scheduler, so no outside code can mark a fiber "internal". Unlike the other hooks, this one
receives a real `Fiber` rather than a coroutine, because the fiber has not been adopted yet.

#### `onDefer(callable $task): bool`

Queue a one-shot microtask; the scheduler runs it on its next tick. The engine never stores tasks:
both `SchedulerHook::defer()` and C-level callers route here, and the queue lives in the scheduler.

#### `onShutdown(): bool`

A graceful shutdown has been requested. The scheduler stops accepting new work and decides the
fate of the remaining coroutines: run them to completion, or cancel them by enqueuing with a
`CancellationError`.

#### `getContext(object $coroutine): object` / `getInternalContext(object $coroutine): object`

Each coroutine has two key/value contexts that follow the logical chain of execution. The
**userland** context uses string/object keys (request id, tracing span, locale); the **internal**
context is a separate store reserved for C extensions, keyed by process-unique numeric keys. These
getters return the context object, read and written through the operations below.

#### `contextFind(object $context, mixed $key): mixed` / `contextSet(...): bool` / `contextUnset(...): bool`

Read, store, and remove values in a context. Keys are strings or objects (compared by identity).
The internal context is operated on by C extensions directly, not through PHP.

### Responsibilities not registered from PHP

A scheduler also fulfils responsibilities that are part of the C contract but are not exposed as
scheduler hooks, because they involve creating opaque engine values or
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
  very latest stage of the request, during and after the teardown of global variables and the
  object store, when userland is already being dismantled and no PHP-registered scheduler can be
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
- when the main script finishes, normally or through `exit()`, the engine drains the remaining
  coroutines to completion and then calls `onShutdown()`;
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
