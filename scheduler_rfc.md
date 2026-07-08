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

This step was anticipated from the start. Fibers were introduced as a deliberately low-level
primitive, on the explicit understanding that a higher-level scheduling layer would be built on
top of them — first in userland, and in time in the engine. This RFC takes that anticipated
engine-level step: it delivers on a direction the Fibers proposal already left room for, rather
than inventing a new one.

**The purpose of this RFC is to give PHP the ability to activate a concurrent execution mode.**
The proposal draws on the implementation experience of the
[TrueAsync project](https://github.com/true-async), a complete concurrency stack for PHP
(scheduler, libuv reactor, thread pool). TrueAsync demonstrated that PHP can be moved to
asynchronous execution *in full*: every blocking I/O function (file and socket operations, DNS,
streams, `sleep()`, …) becomes non-blocking transparently, without any change to existing code,
so a coroutine that would block instead yields and lets others run. It introduces a universal
interface: coroutines become a native engine concept, and the component that drives them, the
scheduler, becomes pluggable. A C extension or a PHP library registers a set of hooks through a
single call, and from that point on PHP operates concurrently.

```php
// MyScheduler implements Async\Scheduler.
Async\SchedulerHook::register('my-scheduler', new MyScheduler());
```

## Scope: what this RFC deliberately does not define

This document defines only the *hook layer*: the set of hooks through which a scheduler extends
the behaviour of the PHP core, without baking any concrete Scheduler implementation into the
engine. **Extensions and third-party code remain free to define arbitrary functions, classes and
APIs on top of the registered scheduler** (`spawn()`, `await()`, channels, futures, an `Async\`
namespace), **and this RFC intentionally defines none of them.** The class of the coroutine object, the transfer
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
4. **Fibers adapted to the coroutine context, backward-compatibly.** Existing `Fiber`-based code
   keeps running unchanged. When a scheduler is active, the `onFiber` hook lets it adapt each
   starting fiber to the coroutine context, adopting it onto the schedule. Fiber-based libraries
   such as ReactPHP/Revolt and AMPHP thus cooperate with the engine instead of each driving
   concurrency in isolation, while the existing behaviour of a plain fiber is preserved.
5. **Free switching between execution contexts.** The engine exposes a low-level symmetric-switch
   primitive, `Continuation`: a scheduler can transfer control directly from one coroutine into
   another (`$coroutine->switchTo()`) instead of routing every hand-off through a central loop.
   This gives schedulers a symmetric coroutine model, built on the engine's own fiber machinery.

## Proposal

### Coroutines

A coroutine is a lightweight unit of execution: a callable with a defined lifecycle.

> created → queued → running → suspended → finished

A coroutine sits at a *higher level of abstraction* than a `Fiber` or a `Continuation`. Those are
the low-level primitives that merely save and restore an execution context; a coroutine is the
schedulable unit the scheduler builds on top of one of them, adding the lifecycle above, a result
or unhandled exception, cancellation, and its execution-flow context. The engine and the hooks
speak in coroutines; which primitive backs a given coroutine (a fiber or a continuation) is the
scheduler's implementation choice.

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
 * The low-level symmetric-switch primitive: a bare execution context. Minted via
 * the createContinuation capability; it is *not* the schedulable unit, a scheduler
 * wraps a Continuation into its own coroutine object. Switching is done on the
 * Continuation itself. Internally backed by the engine's Fiber machinery, so it
 * stays compatible with fiber-aware tooling (e.g. Xdebug).
 */
final class Continuation
{
    /** Switch control into this continuation, optionally sending $value. */
    public function switchTo(mixed $value = null): mixed {}
}

/**
 * A scheduler implements this interface and hands an instance to
 * SchedulerHook::register(). The `on*` methods are *event callbacks* the engine
 * invokes; the engine performs the actual context switches.
 *
 * Two layers. A `Continuation` is the low-level symmetric-switch primitive,
 * minted via createContinuation and entered with its own switchTo(). A
 * *coroutine* is the schedulable unit the scheduler builds on top of a
 * Continuation; the RFC does not type it (it is the scheduler's own object).
 * enqueue/suspend/context and the current-coroutine accessor all speak in
 * coroutines, not continuations.
 */
interface Scheduler
{
    /**
     * The scheduler starts. The engine hands a PHP scheduler its privileged
     * capabilities as plain closures (a C scheduler reaches the primitives
     * directly and receives none). Because they arrive only here, only the
     * scheduler holds them: no other code can mint continuations or read the
     * current coroutine. Switching is not one of these closures: it is done either
     * through the plain Fiber interface (for an adopted fiber) or on the
     * Continuation itself (for the scheduler's own coroutines). Returns the
     * coroutine now current (or null); the engine records it, same as onSuspend().
     */
    public function onLaunch(
        \Closure $createContinuation,  // createContinuation(callable $entry): Continuation
        \Closure $currentCoroutine,    // currentCoroutine(): ?object
    ): ?object;

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
     * enqueue and a resume are the same thing). A non-null `$error` is raised at
     * the coroutine's suspension point; that is how cancellation and IO/timeout
     * failures reach waiting code.
     */
    public function onEnqueue(object $coroutine, ?\Throwable $error = null): bool;

    /**
     * The current flow yields. Pick who runs next and switch into it (with the
     * Continuation's switchTo). Return the coroutine now running; the engine
     * records it as the current coroutine (the only way it is set). `$fromMain`
     * marks the end-of-main handover; `$isBailout` an abnormal termination.
     */
    public function onSuspend(bool $fromMain, bool $isBailout): ?object;

    /** Store a one-shot microtask on the scheduler's queue. */
    public function onDefer(callable $task): bool;

    /**
     * Record a human-readable description of what `$coroutine` is currently
     * waiting for (e.g. "socket #7 (readable)"), attached by whoever suspended it.
     * Used by introspection tooling and deadlock reports.
     */
    public function onWaitInfo(object $coroutine, string $info): bool;

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

### Design rationale

- **An interface, not an array of callables.** A real object shares state through
  `$this`, is type-checked at compile time, and grows by adding methods, the way
  `SessionHandlerInterface` and other engine integration points already work.

- **Privileged operations as hidden closures.** `createContinuation` and `currentCoroutine`
  are handed to `onLaunch()` once, only to the scheduler: a capability, not a global
  function or static method that any code could call.

- **Continuation vs Fiber.** A `Fiber` is asymmetric (yields only to its resumer),
  so A → B costs two switches through an intermediary. A `Continuation` is
  symmetric: `switchTo(B)` goes A → B directly, halving the switches on hot paths
  (channels, generators, pipelines).

- **Xdebug-compatible.** A `Continuation` is built on the same `zend_fiber_context`
  the `Fiber` API uses, so step debugging and stack traces keep working.

### How a scheduler is used

The hooks are simply the seam where a scheduler plugs its implementation into the engine: the
engine calls them, and the scheduler supplies the behaviour. Everything a user sees (`spawn()`,
`await()`, timers, channels) is ordinary code built on top of that seam.

A non-blocking `sleep()`, for instance, is a handful of lines: it remembers the running coroutine,
arms a timer on PHP's built-in Poll API to wake it after the delay, and yields, so the thread runs
other coroutines instead of blocking. In pseudocode:

```php
// Pseudocode — the reactor/helper names are illustrative, not part of this RFC.
function sleep(float $seconds): void
{
    $coroutine = currentCoroutine();                       // the coroutine now running
    Poll::addTimer($seconds, static fn () =>               // PHP's built-in Poll (reactor) API
        resume($coroutine));                               // wake it when the timer fires
    suspend();                                             // yield to the scheduler; others run
}
```

`Poll` is PHP's built-in event/reactor API; `currentCoroutine()`, `resume()` and `suspend()` are
the scheduler's user-facing helpers (`suspend()` routes through the `onSuspend` hook). The RFC
standardises only the hooks underneath, not this surface.

The PHP core keeps track of **which coroutine is currently running**. It learns it from the
scheduler: `onSuspend()` returns the coroutine object it switched to, and the core records that as
the current coroutine. The scheduler reads it back through the `currentCoroutine` closure it was
handed at `onLaunch()`. How the
coroutine is then exposed to userland (a `current()` accessor, a coroutine class, `spawn()`/`await()`)
is **not part of this RFC**: like the coroutine object itself, it belongs to the scheduler's API.

The same split applies to **microtasks**: the one-shot callback queue is owned by the scheduler,
not the engine. `defer()` only forwards the callable to `onDefer()`; storage, draining, and exact
semantics are the scheduler's policy.

### The scheduler and the reactor

The scheduler owns coroutines and a run queue; a *reactor* (event loop over the OS: fds, timers,
signals) is what makes I/O non-blocking. They are two halves of one loop and meet at exactly two
points. The reactor itself is outside this RFC; its C-level interface is a separate document,
[reactor.md](reactor.md).

**1. A reactor callback wakes a coroutine.** A non-blocking operation arms an event on the reactor
and suspends the coroutine; when the event fires, the reactor's callback hands the coroutine back
to the scheduler (`onEnqueue`), moving it from *suspended* to *runnable*. The reactor never runs
coroutine code — it only flips the coroutine to ready. This is exactly the `sleep()` above: its
timer callback calls `resume($coroutine)`.

**2. When idle, the scheduler blocks in the reactor.** When the run queue drains, the scheduler
does not spin: from inside `onSuspend()` it asks the reactor to block until the next OS event. In
pseudocode:

```php
// Pseudocode — the scheduler's onSuspend, blocking in the reactor when idle.
public function onSuspend(bool $fromMain, bool $isBailout): ?object
{
    $current = null;

    while ($this->hasLiveCoroutines()) {
        if ($this->ready->isEmpty()) {
            Poll::run(block: true);        // sleep in the kernel until an fd/timer fires;
        }                                  // its callback re-queues the woken coroutine
        $current = $this->ready->dequeue();
        $current->switchTo();              // (or drive its adopted fiber)
    }

    return $current;
}
```

Coroutines run until they all park on I/O; the queue empties; the scheduler blocks in the reactor;
an OS event fires a callback; the callback re-queues a coroutine; the reactor returns and the
scheduler switches into it. No coroutine is lost and the thread never busy-waits.

### Hook specification

#### `onLaunch(Closure $createContinuation, Closure $currentCoroutine): ?object`

Called once when the scheduler starts: for a C scheduler just before the script runs, for a PHP
scheduler at registration (script code is already running). The engine hands a PHP scheduler its
privileged capabilities as closures: `createContinuation(callable): Continuation` mints a
continuation and `currentCoroutine(): ?object` returns the coroutine the engine records as running.
Switching is not one of them: it is done either through the plain Fiber interface (for an adopted
fiber) or on the Continuation itself (`$continuation->switchTo()`, for the scheduler's own
coroutines). State initialisation belongs here; returns the coroutine now current, or null.

#### `onEnqueue(object $coroutine, ?Throwable $error = null): bool`

Make a coroutine runnable and place it in the run queue. Enqueuing a fresh coroutine and resuming
a suspended one are the same operation. A non-null `$error` is raised at the coroutine's
suspension point, which is how cancellation and IO/timeout failures
reach waiting code. `false` means the coroutine was not accepted (for example during shutdown).

#### `onSuspend(bool $fromMain, bool $isBailout): ?object`

The central scheduling hook: the current flow yields. The scheduler selects the next runnable
coroutine, switches into its Continuation, and returns the coroutine now running; the engine
records it as the current coroutine. `$fromMain` marks the end-of-main handover (drain the
remaining coroutines to completion rather than a single switch); `$isBailout` an abnormal
termination.

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

#### `onWaitInfo(object $coroutine, string $info): bool`

Whoever suspends a coroutine may describe *what it is waiting for* in a human-readable string
(`"socket #7 (readable)"`, `"channel recv"`, …). This hook hands that description to the scheduler,
which stores it against the coroutine for introspection tooling and deadlock reports. It carries
no scheduling effect.

#### `onShutdown(): bool`

A graceful shutdown has been requested. The scheduler stops accepting new work and decides the
fate of the remaining coroutines: run them to completion, or cancel them by enqueuing with an
error.

#### `getContext(object $coroutine): object` / `getInternalContext(object $coroutine): object`

Each coroutine has two key/value contexts that follow the logical chain of execution. The
**userland** context uses string/object keys (request id, tracing span, locale); the **internal**
context is a separate store reserved for C extensions, keyed by process-unique numeric keys. These
getters return the context object, read and written through the operations below.

#### `contextFind(object $context, mixed $key): mixed` / `contextSet(...): bool` / `contextUnset(...): bool`

Read, store, and remove values in a context. Keys are strings or objects (compared by identity).
The internal context is operated on by C extensions directly, not through PHP.

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

## Impact on the ecosystem and prospects

This RFC standardises only the activation seam, but that seam is what an entire concurrency
ecosystem builds on. The [TrueAsync project](https://github.com/true-async) already demonstrates,
in production, what becomes possible once PHP can switch into a concurrent mode.

- **Efficiency.** With transparent async I/O a coroutine costs a fraction of a thread: TrueAsync
  measures roughly **20× less memory** for the same concurrency (54 MiB vs 1.08 GB) and up to
  **13× higher throughput** on IO-bound workloads at identical CPU utilisation. As applications
  shift toward IO-bound work (microservices, cloud APIs), this moves async from a niche
  optimisation to the default shape and brings PHP to throughput parity with Node.js/Python
  without an architectural rewrite — with the optimal concurrency computable rather than guessed
  (`N ≈ 1 + T_io / T_cpu`). See the
  [concurrency-efficiency evidence](https://true-async.github.io/en/docs/evidence/concurrency-efficiency.html).

- **Transparent async, no code changes.** Because blocking I/O becomes non-blocking underneath,
  existing PHP code runs concurrently unchanged: a call that would block yields instead. Field use
  of transparent asynchrony has proved markedly more convenient than the explicit async of Go or
  Python — there is no `async`/`await` colouring and no separate blocking vs non-blocking APIs to
  learn; ordinary sequential code simply scales.

- **Frameworks.** Laravel and Symfony gain concurrency through thin adapters rather than forks:
  [laravel-spawn](https://github.com/YanGusik/laravel-spawn),
  [symfony-spawn](https://github.com/YanGusik/symfony-spawn), and the
  [thrun](https://github.com/YanGusik/thrun) runtime.

- **The server as a first-class citizen.** A long-lived, coroutine-driven server is the natural
  host: gRPC, WebSocket, HTTP/3, HTTP/2 and SSE map cleanly onto coroutines (one coroutine per
  stream/connection), and a stateful runtime reuses connection pools and initialised services
  across requests instead of rebuilding them per request. In synthetic benchmarks the C server
  matches C/Rust implementations, and on real workloads it shows a substantial practical gain.

- **Integrations.** The same model absorbs inherently concurrent external systems — Temporal
  (workflow orchestration), ClickHouse, and other network services — as ordinary coroutine code.

- **Beyond the server.** A [native bridge](https://github.com/true-async/native-bridge) explores
  running the same concurrency model on mobile/native targets, extending PHP's reach beyond the
  classic request/response host.

None of this is defined by this RFC — but all of it depends on the single activation contract it
standardises.

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
  extracted, including the framework adapters, server and integrations referenced above.
- [TrueAsync documentation](https://true-async.github.io): guides, benchmarks and evidence.
- [Concurrency efficiency](https://true-async.github.io/en/docs/evidence/concurrency-efficiency.html):
  the memory and throughput measurements behind the ecosystem-impact section.
- `Io\Poll` (`main/php_poll.h`): the readiness-multiplexing API in php-src master, suitable as the
  IO source for a userland event loop.
- [SCHEDULER.md](SCHEDULER.md): the exact engine invocation points, for implementers.

## Rejected Features

## Changelog
