# PHP RFC: Async Scheduler Hook API

- **Version:** 0.1
- **Date:** 2026-07-02
- **Author:** Edmond, edmondifthen@proton.me
- **Status:** Draft
- **Implementation:** https://github.com/php/php-src/pull/22561
- **Discussion thread:** tbd
- **Voting thread:** tbd

## Introduction

PHP has no engine-level way to run code concurrently. Fibers (PHP 8.1) added the low-level
primitive, cooperative context switching, but no scheduler: deciding what runs, when, and in
which order was left entirely to userland. As a result, each framework maintains its own event
loop, its own coroutine abstraction and its own conventions. These implementations are mutually
incompatible, and the PHP engine has no seam through which it could ever drive any of them.

That gap was left open deliberately. Fibers were introduced as a low-level primitive, on the
explicit understanding that a higher-level scheduling layer would be built on top of them: first
in userland, and in time in the PHP engine. This RFC takes that engine-level step: it follows a
direction the Fibers proposal already left room for, rather than inventing a new one.

**The purpose of this RFC is to give PHP the ability to activate a concurrent execution mode.**
The proposal draws on the implementation experience of the
[TrueAsync project](https://github.com/true-async), a complete concurrency stack for PHP
(scheduler, libuv reactor, thread pool). TrueAsync demonstrated that PHP can be moved to
asynchronous execution *in full*: every blocking I/O function (file and socket operations, DNS,
streams, `sleep()`, …) becomes non-blocking transparently, without any change to existing code:
a coroutine that would block yields instead and lets others run. This RFC extracts the interface
that experience converged on. The PHP engine learns to speak in coroutines, and the component that
drives them, the scheduler, becomes pluggable. A C extension or a PHP library hands the PHP engine
a scheduler factory in a single call, and from that point on PHP operates concurrently.

```php
// The factory receives the scheduler's capabilities and returns the scheduler,
// constructed in a valid state. MyScheduler implements Async\Scheduler.
Async\SchedulerHook::register('my-scheduler',
    fn (callable $createContinuation, callable $currentContinuation, callable $currentCoroutine)
        => new MyScheduler($createContinuation, $currentContinuation, $currentCoroutine));
```

## Scope: what this RFC deliberately does not define

This document defines only the *hook layer*: the set of hooks through which a scheduler extends
the behaviour of the PHP core, without baking any concrete Scheduler implementation into the
PHP engine. **Extensions and third-party code remain free to define arbitrary functions, classes and
APIs on top of the registered scheduler** (`spawn()`, `await()`, channels, futures, an `Async\`
namespace), **and this RFC intentionally defines none of them.** The class of the coroutine
object, the transfer of values between coroutines, and the shape of the user-facing API are the
exclusive domain of the scheduler implementation. The
[True Async RFC](https://wiki.php.net/rfc/true_async) is one such API, built on this core.

This separation is deliberate. The PHP engine standardises *how concurrency is activated and which
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
4. **Backward-compatible fiber adoption.** Existing `Fiber`-based code keeps running unchanged.
   When a scheduler is active, the `onFiber` hook lets it adopt each starting fiber onto its
   schedule. Fiber-based libraries such as ReactPHP/Revolt and AMPHP thus cooperate with the
   PHP engine instead of each driving concurrency in isolation, while a plain fiber keeps its
   existing behaviour.
5. **Direct switching between execution contexts.** The PHP engine exposes a low-level symmetric-switch
   primitive, `Continuation`: a scheduler can transfer control directly from one coroutine into
   another (`$continuation->switchTo()`) instead of routing every hand-off through a central loop.
   This gives schedulers a symmetric coroutine model, built on the PHP engine's own fiber machinery.

## Proposal

### Coroutines

A coroutine is a lightweight unit of execution: a callable with a defined lifecycle.

> created → queued → running → suspended → finished

The middle of the chain is a cycle, not a straight line: a suspended coroutine re-enters the
queue when it is resumed (see `onEnqueue`), and *queued → running → suspended* repeats until the
callable returns or throws.

A coroutine sits at a *higher level of abstraction* than a `Fiber` or a `Continuation`. Those are
the low-level primitives that merely save and restore an execution context; a coroutine is the
schedulable unit the scheduler builds on top of one of them, adding the lifecycle above, a result
or unhandled exception, cancellation, and its execution-flow context. The PHP engine and the hooks
speak in coroutines; which primitive backs a given coroutine (a fiber or a continuation) is the
scheduler's implementation choice.

Two orthogonal attributes may additionally apply: *cancelled* (cancellation has been requested)
and *main* (the coroutine that wraps the top-level script; how it comes to exist is described in
"The main flow is a coroutine too"). Each coroutine records its
completion result or unhandled exception, the source location at which it was spawned, and, while
suspended, a description of what it is waiting for (see the `onWaitInfo` hook).

At the PHP level a coroutine is an opaque object. This RFC does not define its class; the
registered scheduler does.

### The API

The complete surface added by this RFC: three symbols in the `Async\` namespace.

```php
namespace Async;

/**
 * The low-level symmetric-switch primitive: a bare execution context. Minted via
 * the createContinuation capability, or captured from the running flow via
 * currentContinuation (how the main flow is adopted). It is *not* the schedulable
 * unit: a scheduler wraps a Continuation into its own coroutine object. Switching
 * is done on the Continuation itself. Internally backed by the PHP engine's Fiber
 * machinery, so it stays compatible with fiber-aware tooling (e.g. Xdebug).
 */
final class Continuation
{
    /** Switch control into this continuation, optionally sending $value. */
    public function switchTo(mixed $value = null): mixed {}
}

/**
 * A scheduler implements this interface; the factory handed to
 * SchedulerHook::register() returns an instance of it. The `on*` methods are
 * *event callbacks*: the PHP engine decides when to invoke them, the hooks decide
 * which coroutine runs next, and the low-level stack switch itself is always
 * performed by the PHP engine's fiber machinery (behind Continuation::switchTo()
 * and the Fiber API).
 *
 * The PHP engine learns the current coroutine in exactly one way: the return
 * value of onSuspend(). It never chooses one on its own.
 *
 * Two layers. A `Continuation` is the low-level symmetric-switch primitive,
 * minted via createContinuation and entered with its own switchTo(). A
 * *coroutine* is the schedulable unit the scheduler builds on top of a
 * Continuation; the RFC does not type it (it is the scheduler's own object).
 * enqueue/suspend/context and the current-coroutine accessor all speak in
 * coroutines, not continuations.
 *
 * Hooks that return `bool` report acceptance: `true` means the event was
 * handled, `false` (or a thrown exception) that it was not.
 */
interface Scheduler
{
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
     * Continuation's switchTo). Return the coroutine now running; the PHP engine
     * records it as the current coroutine (the single way it ever learns it).
     * `$fromMain` marks the end-of-main handover; `$isBailout` an abnormal
     * termination of the main flow (a fatal error): the last opportunity to
     * clean up resources.
     */
    public function onSuspend(bool $fromMain, bool $isBailout): ?object;

    /** Queue a one-shot microtask on the scheduler's queue. */
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
     * Registers a scheduler and activates the concurrent mode. The factory
     * receives the scheduler's privileged capabilities and returns the
     * scheduler, constructed already holding them:
     *
     *     fn (callable $createContinuation,   // createContinuation(callable $entry): Continuation
     *         callable $currentContinuation,  // currentContinuation(): Continuation
     *         callable $currentCoroutine,     // currentCoroutine(): ?object
     *     ): Async\Scheduler
     *
     * Because the capabilities are handed only to the factory, only the
     * scheduler holds them: no other code can mint or capture continuations,
     * or read the current coroutine. Switching is not one of them: it is done
     * either through the plain Fiber interface (for an adopted fiber) or on
     * the Continuation itself (for the scheduler's own coroutines).
     *
     * A scheduler is registered exactly once per process: if one is already
     * registered, whether by a C extension or by an earlier call, this method
     * throws an Error.
     */
    public static function register(string $module, callable $factory): bool {}

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
  `$this` and is type-checked at compile time, following existing PHP engine
  integration points such as `SessionHandlerInterface`. Later revisions can add
  optional companion interfaces (the way session handlers gained
  `SessionUpdateTimestampHandlerInterface`) without breaking implementations.

- **A factory, not a ready instance.** `register()` takes a callable that receives the
  capabilities and returns the scheduler. The scheduler is therefore constructed already
  holding its mandate: no half-initialised object, no nullable capability properties, no
  window in which the scheduler exists but cannot act.

- **Privileged operations as hidden capabilities.** `createContinuation`, `currentContinuation`
  and `currentCoroutine` are handed to the factory once, only to the scheduler: a capability,
  not a global function or static method that any code could call.

- **Continuation vs Fiber.** A `Fiber` is asymmetric (yields only to its resumer),
  so A → B costs two switches through an intermediary. A `Continuation` is
  symmetric: `switchTo(B)` goes A → B directly, halving the switches on hot paths
  (channels, generators, pipelines).

- **Xdebug-compatible.** A `Continuation` is built on the same `zend_fiber_context`
  the `Fiber` API uses, so step debugging and stack traces keep working.

### How a scheduler is used

The hooks are simply the seam where a scheduler plugs its implementation into the PHP engine: the
PHP engine calls them, and the scheduler supplies the behaviour. Everything a user sees (`spawn()`,
`await()`, timers, channels) is ordinary code built on top of that seam.

A non-blocking `sleep()`, for instance, is a handful of lines: it remembers the running coroutine,
arms a timer on PHP's built-in Poll API to wake it after the delay, and yields, so the thread runs
other coroutines instead of blocking. In pseudocode:

```php
// Pseudocode: the reactor/helper names are illustrative, not part of this RFC.
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

The PHP core keeps track of **which coroutine is currently running**, but never chooses it: the
scheduler reports it through the return value of `onSuspend()` (the single way it is ever set),
and reads it back through the `currentCoroutine` capability handed to its factory. How the
coroutine is then exposed to userland (a `current()` accessor, a coroutine class,
`spawn()`/`await()`) is **not part of this RFC**: like the coroutine object itself, it belongs
to the scheduler's API.

The same split applies to **microtasks**: the one-shot callback queue is owned by the scheduler,
not the PHP engine. `defer()` only forwards the callable to `onDefer()`; storage, draining, and exact
semantics are the scheduler's policy.

### The scheduler and the reactor

The scheduler owns coroutines and a run queue; a *reactor* (event loop over the OS: fds, timers,
signals) is what makes I/O non-blocking. They are two halves of one loop and meet at exactly two
points. The reactor itself is outside this RFC; its C-level interface is a separate document,
[reactor.md](https://github.com/true-async/php-async-core-rfc/blob/main/reactor.md).

**1. A reactor callback wakes a coroutine.** A non-blocking operation arms an event on the reactor
and suspends the coroutine; when the event fires, the reactor's callback hands the coroutine back
to the scheduler (`onEnqueue`), moving it from *suspended* to *runnable*. The reactor never runs
coroutine code; it only flips the coroutine to ready. This is exactly the `sleep()` above: its
timer callback calls `resume($coroutine)`.

**2. When idle, the scheduler blocks in the reactor.** When the run queue drains, the scheduler
does not spin: from inside `onSuspend()` it asks the reactor to block until the next OS event. In
pseudocode:

```php
// Pseudocode: the scheduler's onSuspend, blocking in the reactor when idle.
public function onSuspend(bool $fromMain, bool $isBailout): ?object
{
    $self = $this->normaliseCaller();      // the yielding flow, main included
                                           // (see "The main flow is a coroutine too")
    while ($this->hasLiveCoroutines()) {
        if ($this->ready->isEmpty()) {
            Poll::run(block: true);        // sleep in the kernel until an fd/timer fires;
        }                                  // its callback re-queues the woken coroutine
        
		$current = $this->ready->dequeue();
        $current->switchTo();              // (or drive its adopted fiber)
    }

    return $self;                          // this frame resumes when $self runs again
}
```

Coroutines run until they all park on I/O; the queue empties; the scheduler blocks in the reactor;
an OS event fires a callback; the callback re-queues a coroutine; the reactor returns and the
scheduler switches into it. No coroutine is lost and the thread never busy-waits.

### The main flow is a coroutine too

One flow exists before the scheduler ever runs: the top-level script itself. The PHP engine starts
it, not the scheduler, so at first it is the only schedulable flow without a coroutine object.
The system normalises itself at the first yield: `onSuspend()` captures the currently running
context with the `currentContinuation` capability, wraps it into the scheduler's own coroutine
object, and marks it *main* (the attribute from the Coroutines section). From that point the
top-level script is suspended, enqueued and resumed like any other coroutine; nothing in the
scheduler treats it specially afterwards.

That uniformity is the point. If the main flow stayed special, every path through a scheduler
would fork in two: switch into a coroutine or back into main, cancel a coroutine or shield main,
queue coroutines but keep a dedicated slot for the one flow that is not one. Adopting main once,
at its first yield, deletes the second branch everywhere: the queues hold one type, the switch
path is single, cancellation and introspection see only ordinary coroutines. State becomes
uniform for the same reason: the per-coroutine machinery, the two contexts and the wait-info
descriptions, applies to the main flow simply because it is a coroutine. Output buffering shows
this concretely: buffers opened by the plain request flow move into the main coroutine's context
when concurrency starts (see [context_examples.md](https://github.com/true-async/php-async-core-rfc/blob/main/context_examples.md)), rather than living in
a main-only global beside everyone else's per-coroutine state.

This normalisation also pins down the return value of `onSuspend()` precisely. The call returns
when something switches back into the yielding flow, and the flow running at that moment is
exactly the one the hook normalised: so the scheduler returns that coroutine object, and the
PHP engine records it as current. In particular, the end-of-main handover returns the *main*
coroutine, not null. This is how the reference implementation behaves: TrueAsync represents the
main flow as a coroutine and reports it from the end-of-main handover.

### A minimal scheduler

The hook specification below is easier to read against a concrete implementation. The following
scheduler is deliberately naive (FIFO order, no reactor, no cancellation policy), but it is
structurally complete: every hook is implemented, and nothing else is needed to run PHP
concurrently.

```php
// Pseudocode. MyCoroutine is the scheduler's own class: the opaque object all
// hooks speak in. The RFC does not define it; its shape is the scheduler's choice.
final class MyCoroutine
{
    public ?Async\Continuation $continuation = null;
    public ?\Throwable $pendingError = null;
    public bool $isMain = false;
    public object $context;
    public object $internal;

    public function __construct(public readonly \Closure|\Fiber|Async\Continuation $body)
    {
        if ($body instanceof Async\Continuation) {
            $this->continuation = $body;   // an already-running flow, adopted as is
        }

        $this->context = new \stdClass();
        $this->internal = new \stdClass();
    }
}

final class MiniScheduler implements Async\Scheduler
{
    private \SplQueue $ready;               // runnable coroutines
    private \SplQueue $microtasks;          // onDefer() queue, drained once per tick
    private \SplObjectStorage $waitInfo;    // coroutine => what it is waiting for
    private ?MyCoroutine $main = null;      // the adopted main flow

    // The capabilities arrive through the constructor, so the scheduler is
    // created in a valid state and only the scheduler holds them.
    public function __construct(
        private readonly \Closure $createContinuation,   // createContinuation(callable): Continuation
        private readonly \Closure $currentContinuation,  // currentContinuation(): Continuation
        private readonly \Closure $currentCoroutine,     // currentCoroutine(): ?object
    ) {
        $this->ready = new \SplQueue();
        $this->microtasks = new \SplQueue();
        $this->waitInfo = new \SplObjectStorage();
    }

    // spawn() is the scheduler's own API, not part of the RFC: wrap the entry
    // into the scheduler's coroutine object and schedule it.
    public function spawn(\Closure $entry): MyCoroutine
    {
        $coroutine = new MyCoroutine($entry);
        $this->onEnqueue($coroutine);
        return $coroutine;
    }

    public function onEnqueue(object $coroutine, ?\Throwable $error = null): bool
    {
        $coroutine->pendingError = $error;           // surfaces at the suspension point
        $this->ready->enqueue($coroutine);
        return true;
    }

    public function onSuspend(bool $fromMain, bool $isBailout): ?object
    {
        // Normalise the yielding flow first: on its first yield the main flow
        // has no coroutine object yet, so capture and adopt it (see "The main
        // flow is a coroutine too"). $fromMain marks the end-of-main drain;
        // $isBailout, the last chance to release resources.
        $self = ($this->currentCoroutine)();

        if ($self === null) {
            $self = $this->main = new MyCoroutine(($this->currentContinuation)());
            $self->isMain = true;
        }

        while (!$this->ready->isEmpty() || !$this->microtasks->isEmpty()) {
            while (!$this->microtasks->isEmpty()) {
                ($this->microtasks->dequeue())();    // one tick of microtasks
            }

            if ($this->ready->isEmpty()) {
                break;                               // a real scheduler blocks in
            }                                        // the reactor here

            $current = $this->ready->dequeue();
            $current->continuation ??= ($this->createContinuation)($current->body);
            $current->continuation->switchTo();      // or drive an adopted fiber;
        }                                            // a pendingError is thrown at the
                                                     // suspension point inside
        return $self;    // this frame resumes when $self runs again: record it
    }

    public function onFiber(\Fiber $fiber): ?object
    {
        // Adopt every foreign fiber (this scheduler creates none of its own).
        return new MyCoroutine($fiber);
    }

    public function onDefer(callable $task): bool
    {
        $this->microtasks->enqueue($task);
        return true;
    }

    public function onWaitInfo(object $coroutine, string $info): bool
    {
        $this->waitInfo[$coroutine] = $info;         // introspection, deadlock reports
        return true;
    }

    public function onShutdown(): bool
    {
        // Policy decision: stop accepting work, then cancel or drain the rest.
        return true;
    }

    // Contexts: plain per-coroutine stores; the storage strategy (arrays,
    // SplObjectStorage for object keys, ...) is the scheduler's choice.
    public function getContext(object $coroutine): object         { return $coroutine->context; }
    public function getInternalContext(object $coroutine): object { return $coroutine->internal; }

    public function contextFind(object $context, mixed $key): mixed { /* ... */ }
    public function contextSet(object $context, mixed $key, mixed $value): bool { /* ... */ }
    public function contextUnset(object $context, mixed $key): bool { /* ... */ }
}

Async\SchedulerHook::register('mini',
    fn (callable $create, callable $capture, callable $current)
        => new MiniScheduler($create, $capture, $current));
```

Three things are worth noticing. The PHP engine never sees a queue, a policy or a coroutine class:
it only fires the hooks and records what `onSuspend()` returns. The user-facing API (`spawn()`
here) is ordinary code the scheduler adds on top. And replacing the naive loop with the reactor
version from the previous section turns this sketch into a real event-driven scheduler without
changing any signature.

### Hook specification

#### `SchedulerHook::register(string $module, callable $factory): bool`

Registers the scheduler by invoking its factory. It is not itself a hook, but every other hook
depends on a scheduler having been registered, so it is specified first.

The factory runs once, at the moment the scheduler starts: for a C scheduler, just before the
script runs; for a PHP scheduler, synchronously inside `register()`, since the PHP engine's own
launch point has already passed.

The factory receives the scheduler's privileged capabilities as callables:

- `createContinuation(callable): Continuation` — mints a continuation
- `currentContinuation(): Continuation` — captures the context currently running (how the main
  flow is adopted, see above)
- `currentCoroutine(): ?object` — returns the coroutine the PHP engine records as running

Switching is not one of them: it is done either through the plain Fiber interface (for an
adopted fiber) or on the Continuation itself (`$continuation->switchTo()`, for the scheduler's
own coroutines).

The factory returns the scheduler, already constructed with these capabilities; state
initialisation belongs in its constructor.

#### `onEnqueue(object $coroutine, ?Throwable $error = null): bool`

Make a coroutine runnable and place it in the run queue. Enqueuing a fresh coroutine and resuming
a suspended one are the same operation. A non-null `$error` is raised at the coroutine's
suspension point, which is how cancellation and IO/timeout failures reach waiting code. `false`
means the coroutine was not accepted (for example during shutdown).

#### `onSuspend(bool $fromMain, bool $isBailout): ?object`

The hook fires when a coroutine voluntarily yields control to the scheduler. The scheduler must
either switch context to another runnable coroutine or enter an indefinite wait for events.

On the first call, the main flow has no coroutine object yet: the scheduler captures it with
`currentContinuation`, wraps it and marks it *main* (see "The main flow is a coroutine too"). The
call returns when something switches back into the yielding flow; the PHP engine records the
returned coroutine as the current one.

The two flags:

- `$fromMain` — the main flow of execution has finished. The main flow is the entry point PHP
  itself starts execution from. Instead of a single switch, the scheduler must drain the
  remaining coroutines, and the hook returns the *main* coroutine.
- `$isBailout` — set alongside `$fromMain` when the main flow terminated abnormally (`exit()`, a
  fatal error); the scheduler may then discard the remaining work instead of completing it. This
  call can arrive while the PHP engine is already terminating, and it is the scheduler's last chance
  to clean up: release connections and cancel the remaining coroutines so their cleanup handlers
  run before the request is torn down.

#### `onFiber(Fiber $fiber): ?object`

The hook fires when a `Fiber` is created, so the scheduler can bind a coroutine to it. Called on
every `Fiber::start()` while a scheduler is active. Return a coroutine to adopt the fiber — its
`suspend()`/`resume()` then route through the scheduler, turning it into a stackful coroutine;
return `null` to leave it a plain low-level fiber. When the hook is absent, every fiber stays
low-level.

This exists for backward compatibility: Revolt (and ReactPHP, AMPHP) already use fibers as their
own context-switching primitive, driving their event loop directly through `Fiber::suspend()`/
`resume()`. If the PHP engine adopted every fiber automatically, such a scheduler would recurse into
itself. So the scheduler decides per fiber, keeping its own private set of the fibers it created
for itself, returning `null` for those and a coroutine for the rest — the PHP engine tracks nothing,
so no outside code can mark a fiber "internal". Unlike the other hooks, this one receives a real
`Fiber` rather than a coroutine, because the fiber has not been adopted yet.

#### `onDefer(callable $task): bool`

Queue a one-shot microtask; the scheduler runs it on its next tick. The PHP engine never stores tasks:
both `SchedulerHook::defer()` and C-level callers route here, and the queue lives in the scheduler.
What microtasks are for, and the concurrent-iterator pattern built on them, is shown in
"Microtasks in practice: a concurrent iterator" below.

#### `onWaitInfo(object $coroutine, string $info): bool`

Lets the caller record what a coroutine is waiting for, as a human-readable string
(`"socket #7 (readable)"`, `"channel recv"`, …). May be called more than once if the coroutine is
waiting on several events at once. The scheduler stores each description against the coroutine
for introspection tooling and deadlock reports; the call carries no scheduling effect.

#### `onShutdown(): bool`

The hook fires right before the graceful shutdown phase begins, so the scheduler can clean up its
own state. The request is not tied to a fixed lifecycle point: it comes from whoever decides that
concurrency must end early — in the reference stack, `exit()` inside a coroutine triggers it
instead of tearing the request down mid-flight. From here the scheduler stops accepting new work
and decides the fate of the remaining coroutines: run them to completion, or cancel them by
enqueuing with an error.

#### `getContext(object $coroutine): object` / `getInternalContext(object $coroutine): object`

Each coroutine carries two key/value contexts. The **userland** context uses string/object keys
(request id, tracing span, locale); the **internal** context is a separate store reserved for C
extensions, keyed by process-unique numeric keys. These getters return the context object, which
is read and written through the operations below. Whether a context is inherited along spawn
chains is the scheduler's policy, not part of this contract.

Object keys exist so a library can keep its context entry private: an object only it holds a
reference to cannot be read or overwritten by unrelated code guessing a string key. This is the
same encapsulation pattern JavaScript relies on for private state (a private `Symbol`).

The two stores are separate for safety, not convenience. Internal-context values are raw C data
(the worked examples in [context_examples.md](https://github.com/true-async/php-async-core-rfc/blob/main/context_examples.md) store bare pointers),
addressed by numeric keys that PHP code cannot even name. If C-extension state lived in the
userland context, ordinary PHP code could reach it through the same context operations it uses
for its own keys: overwrite a pointer, unset an entry whose memory C code still owns, and
thereby corrupt C state or silently change core behaviour. The internal context is therefore
structurally inaccessible from PHP; the boundary is enforced by construction rather than by
convention.

#### `contextFind(object $context, mixed $key): mixed` / `contextSet(...): bool` / `contextUnset(...): bool`

Read, store, and remove values in a context. Keys are strings or objects (compared by identity).
The internal context is operated on by C extensions directly, not through PHP.

### The internal context in practice

Some functions need to keep state tied to a coroutine. An example is `ob_start()`: it pushes a
handler onto a stack, and with thousands of coroutines interleaving in one process, a single
process-global stack would mix their output. Moving the handler stack into the coroutine's
internal context fixes that without changing a line of userland code — and the same four-step
pattern (allocate a key, create state on first use, dispose it on the coroutine's finish event,
resolve through the current coroutine) applies to any core subsystem or extension with
process-global state to make coroutine-safe.

[context_examples.md](https://github.com/true-async/php-async-core-rfc/blob/main/context_examples.md) collects the worked examples with the actual code:
output buffering and `gethostbyname()`, whose traditional static result buffer becomes
per-coroutine state the same way.

### Microtasks

A microtask is a way to extend the scheduler's own behavior: a callable that runs inside the tick,
between coroutine switches. It runs in the scheduler's own context and never suspends; it runs to
completion right where the scheduler stands. That makes it far cheaper than a coroutine, and the right tool
when logic must execute at scheduling points but does not itself wait: bookkeeping, waking
sleepers, and incremental algorithms sliced across ticks.

One example use is a concurrent iterator: a worker coroutine drives the loop, and a microtask
watchdog spawns a replacement worker whenever the current one suspends, so exactly one coroutine
drives the loop at a time. This is also exactly how the PHP engine itself runs object destructors
during GC in concurrent mode. See
[context_examples.md](https://github.com/true-async/php-async-core-rfc/blob/main/context_examples.md)
for the worked-out code for both.

### PHP engine invocation points

Once registered, the scheduler is **always active**: there is no lazy initialisation and no
implicit start on the first asynchronous call.

- **Launch.** A C-registered scheduler launches immediately before the script code runs. For a
  PHP-registered one the launch moment is the factory call inside `register()` itself, because
  the PHP engine's own launch point has already passed by the time userland code executes.
- **End of main.** When the main script ends, the PHP engine hands control to the scheduler with
  `onSuspend(fromMain: true)`. After a normal completion the scheduler drains the remaining
  coroutines to completion and returns the *main* coroutine (the main flow adopted at its first
  yield); after an abnormal completion (`exit()`, a fatal error) the same handover carries
  `$isBailout = true`, and the scheduler decides whether to finish or discard the remaining
  work; this is its last opportunity to release resources before the request is torn down.
- **After destructors.** Once object destructors have run, the scheduler receives one final
  `onSuspend(fromMain: true)` handover (destructors may have spawned coroutines). After it
  returns, concurrency is terminated and the rest of the request shutdown is synchronous.

Consequently, a script that spawns background work and reaches its final statement does not
silently discard that work: the scheduler defines the semantics of the end of the request.

## Backward Incompatible Changes

Three symbols are added to the `Async\` namespace: the `SchedulerHook` class, the `Scheduler`
interface and the `Continuation` class. Code declaring any of these exact names would break; no
significant usage is known.

No other observable changes are introduced. With no scheduler registered, PHP behaves exactly as
before.

## Proposed PHP Version(s)

A PHP 8.x release after 8.6 (the next minor available for new features).

## RFC Impact

- **To SAPIs:** none observable. CLI, FPM and phpdbg gain the invocation points described above,
  all inactive without a registered scheduler.
- **To Existing Extensions:** none by default. Extensions requiring async awareness receive a
  dedicated internal per-coroutine context keyed by process-unique numeric keys, inaccessible
  from PHP code. One concrete case: `pcntl_fork()` now throws when a coroutine other than the
  main one is alive, since a forked child cannot inherit a working copy of the reactor's state
  (see [reactor.md §12](https://github.com/true-async/php-async-core-rfc/blob/main/reactor.md)).
- **To the Ecosystem:** stubs for one interface and two classes. Event-loop libraries (Revolt,
  ReactPHP, AMPHP, Swoole) obtain a common registration point in place of private, incompatible
  cores.

## Role in the ecosystem

This RFC standardises only the activation seam, but that seam is what an entire concurrency
ecosystem can be built on. The [TrueAsync project](https://github.com/true-async) is one such
ecosystem, working today; the points below describe what the seam makes possible, with TrueAsync
as the existence proof.

- **Efficiency.** The gain is structural, not something TrueAsync invents, and it is worth stating
  precisely rather than as a single speed-up factor. In a coroutine model the unit of concurrency
  is a few kilobytes of state inside one shared process; in the process-per-request model it is an
  entire PHP process, typically tens of megabytes. Memory spent on concurrency therefore stays
  nearly flat as the number of in-flight requests grows, rather than growing linearly with it.
  Throughput is a more modest story. On CPU-bound work coroutines change essentially nothing: a
  correctly sized process pool saturates the same cores. The advantage appears as the IO share of a
  request grows, because a blocking pool must dedicate a whole process to every request that is
  merely waiting on the network, so it reaches its memory and scheduling limits long before the CPU
  is saturated, whereas coroutines hold thousands of waiting requests at negligible cost. Standard
  queueing analysis (Little's law; the classical pool-sizing estimate `N ≈ cores × (1 + T_io/T_cpu)`)
  gives a first-order estimate of the useful concurrency level from measurable quantities. It is a
  starting point, not a guarantee: in practice the ceiling is usually the database connection pool
  or a downstream service. As web workloads continue to shift toward IO (microservices, remote
  APIs), this is the regime that matters increasingly often; the
  [concurrency-efficiency model](https://true-async.github.io/en/docs/evidence/concurrency-efficiency.html)
  works through these numbers and their assumptions.

- **Transparent async, minimal code changes.** Because blocking I/O becomes non-blocking
  underneath, existing PHP code runs concurrently with only minimal adaptation, not a rewrite; the
  framework adapters below (laravel-spawn, symfony-spawn) show how small that adaptation is.
  This is the colourless model of Go's goroutines rather than the explicit `async`/`await` of
  JavaScript, Python or Rust: no function colouring, no parallel sets of blocking and
  non-blocking APIs to learn; ordinary sequential code scales with light touch-ups.

- **Frameworks.** [laravel-spawn](https://github.com/YanGusik/laravel-spawn),
  [symfony-spawn](https://github.com/YanGusik/symfony-spawn) and the
  [thrun](https://github.com/YanGusik/thrun) runtime exist to explore how established frameworks
  behave in a concurrent environment: where per-request assumptions surface, and how much
  adaptation running Laravel or Symfony on coroutines actually takes. So far the answer has been
  a thin adapter, not a fork.

- **The server as a first-class citizen.** A long-lived, coroutine-driven
  [server](https://github.com/true-async/server) is the natural host: gRPC, WebSocket, HTTP/3,
  HTTP/2 and SSE map cleanly onto coroutines (one coroutine per stream/connection), and a stateful
  runtime reuses connection pools and initialised services across requests instead of rebuilding
  them per request. There is also a working integration with the existing
  [FrankenPHP](https://github.com/true-async/frankenphp) app server, so an established runtime can
  adopt the coroutine model rather than being replaced.

- **Integrations.** The same model absorbs inherently concurrent external systems as ordinary
  coroutine code: [Temporal](https://github.com/true-async/php-temporal) (workflow orchestration),
  [ClickHouse](https://github.com/true-async/php-clickhouse), and
  [Redis](https://github.com/true-async/phpredis), where a blocking read on a Redis Stream
  becomes a suspended coroutine instead of a stalled worker. The same shape extends naturally to
  message brokers such as Kafka or AMQP: a consumer is a long-lived read loop, one coroutine per
  queue or partition, running as ordinary library code rather than a dedicated daemon process.

- **Beyond the server.** A [native bridge](https://github.com/true-async/native-bridge) explores
  running the same concurrency model on mobile/native targets, extending PHP's reach beyond the
  classic request/response host. **UI** is the natural test case: a responsive interface must
  never block its main loop, and an event-driven, non-blocking runtime is precisely the
  precondition that PHP has so far lacked for mobile and desktop work.

None of this is defined by this RFC, but all of it depends on the single activation contract it
standardises.

## Future Scope

This core is deliberately minimal. It is the foundation for a series of follow-up RFCs that build
on the activation contract without changing it:

- **Asynchronous I/O.** A standard non-blocking I/O layer (sockets, files, DNS, timers) so that the
  PHP engine's blocking functions transparently yield when a scheduler is active.
- **Threads.** A native threading / parallelism model that cooperates with the scheduler.
- **Connection pooling (PDO Pool).** A shared, coroutine-aware connection pool (for example for
  PDO) that reuses database connections across coroutines and requests.

## Voting Choices

Yes/no vote, 2/3 majority required: "Accept the Async Scheduler Hook API RFC?"

## Patches and Tests

- Proof of concept: https://github.com/true-async/php-src/tree/async-core
  (core, PHP engine invocation points, phpdbg, the PHP registration bridge and its tests).
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
  the queueing model and worked example behind the ecosystem-role section.
- `Io\Poll` (`main/php_poll.h`): the readiness-multiplexing API in php-src master, suitable as the
  IO source for a userland event loop.
- [SCHEDULER.md](https://github.com/true-async/php-async-core-rfc/blob/main/SCHEDULER.md): the exact PHP engine invocation points, for implementers.
- [context_examples.md](https://github.com/true-async/php-async-core-rfc/blob/main/context_examples.md): how PHP core uses the per-coroutine contexts
  (`ob_start()` buffering, `gethostbyname()`), and why the internal context is isolated from
  userland.

## Rejected Features

None yet.

## Changelog

- **0.1**: initial draft.
