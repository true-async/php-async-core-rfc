# PHP RFC: Async Scheduler Hook API

- **Version:** 0.4
- **Date:** 2026-07-10
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
object, the transfer of values between coroutines, and the shape
of the user-facing API are the exclusive domain of the scheduler implementation. The
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

The complete surface added by this RFC: five symbols in the `Async\` namespace.

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
    /**
     * Switch control into this continuation. $value is delivered as the return
     * value of the switchTo() call the target is suspended in; a non-null
     * $error is thrown from that call instead (passing both is a ValueError).
     * The full transfer contract, including the first entry and the finished
     * continuation, is specified in "Exceptions and value transfer".
     */
    public function switchTo(mixed $value = null, ?\Throwable $error = null): mixed {}
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
 * enqueue/suspend and the current-coroutine accessor all speak in
 * coroutines, not continuations.
 *
 * Failures are reported by exceptions, never by return values. Where a hook
 * does return `bool`, the value is data, not a status: onEnqueue() reports
 * whether the coroutine was accepted (false during shutdown is a normal
 * state). Every other hook returns void; a hook that cannot do its job
 * throws (see "Exceptions").
 */
interface Scheduler
{
    /** A graceful shutdown has been requested. */
    public function onShutdown(): void;

    /**
     * A foreign Fiber (created by application/third-party code, e.g.
     * Revolt/AMPHP) is starting. Return a coroutine to adopt it onto the
     * schedule, or null to leave it a plain low-level fiber.
     */
    public function onFiber(\Fiber $fiber): ?object;

    /**
     * Make a coroutine runnable: the single "schedule it" operation (a fresh
     * enqueue and a resume are the same thing). A non-null `$error` is raised at
     * the coroutine's suspension point (the scheduler delivers it through the
     * $error parameter of switchTo()); that is how cancellation and IO/timeout
     * failures reach waiting code. Returns true when the coroutine is queued
     * and will run; false when it was not accepted (the scheduler is shutting
     * down): a quiet rejection, not an error.
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
    public function onDefer(callable $task): void;

    /**
     * Record a human-readable description of what `$coroutine` is currently
     * waiting for (e.g. "socket #7 (readable)"), attached by whoever suspended it.
     * Used by introspection tooling and deadlock reports.
     */
    public function onWaitInfo(object $coroutine, string $info): void;
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
     * throws an Error. Any other failure is an Error too; the method never
     * reports one through a return value.
     */
    public static function register(string $module, callable $factory): void {}

    /** The module name of the registered scheduler, or null when none. */
    public static function getModule(): ?string {}

    /**
     * Queues a callable on the scheduler's microtask queue (one-shot, runs on
     * the next tick). Forwards to the scheduler's onDefer() hook.
     */
    public static function defer(callable $task): void {}
}

/**
 * The userland context of a coroutine: coroutine-local key-value storage with
 * string or object keys. Created lazily on first access and destroyed with the
 * coroutine. The engine provides storage and access only: a fresh coroutine
 * starts with an empty context, and any inheritance policy (what a child sees
 * of the spawner's values) belongs to the scheduler's user-facing API, next to
 * spawn(). See "The coroutine context".
 */
final class Context
{
    /** The value stored under $key, or null when absent. */
    public function find(string|object $key): mixed {}

    /** Whether $key exists: distinguishes an absent key from a stored null. */
    public function has(string|object $key): bool {}

    /** Store $value under $key. Returns $this for chaining. */
    public function set(string|object $key, mixed $value): Context {}

    /** Remove $key. The return value is data, not a status: whether the key existed. */
    public function unset(string|object $key): bool {}
}

/**
 * The context of $coroutine, or of the currently running flow when null (main
 * included, whether or not a scheduler is registered). The explicit-object form
 * is how a scheduler reaches a coroutine's context from outside it, e.g. to
 * implement its inheritance policy in spawn().
 */
function get_context(?object $coroutine = null): Context {}
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
            continue;                      // its callback re-queues the woken coroutine
        }

        $current = $this->ready->dequeue();

        if ($current === $self) {
            break;                         // its own turn came: return from the suspension
        }

        $this->handoff = $current;         // mark the deliberate wake (see the worked
        $current->switchTo();              // example below), or drive its adopted fiber

        if ($this->handoff === $self) {
            break;                         // $self was dequeued while it was scheduling
        }
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
uniform for the same reason: the per-coroutine machinery, the internal context and the wait-info
descriptions, applies to the main flow simply because it is a coroutine. Output buffering shows
this concretely: buffers opened by the plain request flow move into the main coroutine's context
when concurrency starts (see [scheduler_rfc_examples.md](https://github.com/true-async/php-async-core-rfc/blob/main/scheduler_rfc_examples.md)), rather than living in
a main-only global beside everyone else's per-coroutine state.

This normalisation also pins down the return value of `onSuspend()` precisely. The call returns
when something switches back into the yielding flow, and the flow running at that moment is
exactly the one the hook normalised: so the scheduler returns that coroutine object, and the
PHP engine records it as current. In particular, the end-of-main handover returns the *main*
coroutine, not null. This is how the reference implementation behaves: TrueAsync represents the
main flow as a coroutine and reports it from the end-of-main handover.

### Exceptions and value transfer

`switchTo()` is both a send and a receive. One call does two things: it hands control away
together with a value, and, when some later flow switches back, it returns the value that flow
passed. `$value` is therefore delivered as *the return value of the `switchTo()` call the target
is currently suspended in*. This is the symmetric analogue of the `Fiber::resume($v)` /
"`$v` comes back from `Fiber::suspend()`" pair, with no intermediary.

The `$error` parameter uses the same channel with the opposite polarity: instead of returning
`$value` from the target's pending `switchTo()`, it throws `$error` from it. This is the
primitive behind the `onEnqueue()` contract ("a non-null `$error` is raised at the coroutine's
suspension point"): the scheduler delivers cancellation and IO/timeout failures by switching
into the coroutine with the error instead of a value. Passing both a non-null `$value` and an
`$error` is a `ValueError`: there is nowhere the value could arrive.

The boundary cases complete the contract:

- **First entry.** A fresh continuation has no pending `switchTo()` to deliver into: the value
  of a first entry is ignored (the body takes its arguments from the callable handed to
  `createContinuation`). A first entry with an `$error` does not start the body at all: the
  continuation finishes with that exception, which then surfaces at the switch site as below.
  This is precisely the cancellation of a coroutine that never ran.
- **Body completion.** When the continuation's callable returns, the result is delivered through
  the same channel: it becomes the return value of the `switchTo()` call of whoever switched
  into the continuation last. An exception the body does not catch travels the same way, as a
  throw: it surfaces *at the switch site*, inside the flow that performed the last `switchTo()`.
  In practice that flow is the scheduler's `onSuspend()` loop, so a scheduler must wrap its
  switches and record what escapes as the coroutine's unhandled exception (the attribute from
  the Coroutines section). A bailout (fatal error) is not an exception and propagates as a
  bailout across the switch.
- **Finished continuation.** Switching into a continuation whose body has completed throws an
  `Error`.

Exceptions escaping a *hook* follow from where the hook was called. When userland frames sit
beneath the hook invocation (an `onSuspend()` reached from application code, e.g. inside a
suspending `sleep()`), the exception surfaces at that suspension point, in the flow that
yielded: the natural reading of "the operation failed". When no userland frame exists (the
end-of-main handover, an `onEnqueue()` fired by a reactor callback), there is no one to deliver
to: the exception is treated as unhandled, reported, and the request is torn down. Hooks should
therefore not let exceptions escape; a scheduler that can neither handle an event nor recover
throws deliberately, knowing the above is the consequence.

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
    public ?\Throwable $unhandledException = null;
    public bool $isMain = false;

    public function __construct(public readonly \Closure|\Fiber|Async\Continuation $body)
    {
        if ($body instanceof Async\Continuation) {
            $this->continuation = $body;   // an already-running flow, adopted as is
        }
    }
}

final class MiniScheduler implements Async\Scheduler
{
    private \SplQueue $ready;               // runnable coroutines
    private \SplQueue $microtasks;          // onDefer() queue, drained once per tick
    private \SplObjectStorage $waitInfo;    // coroutine => what it is waiting for
    private ?MyCoroutine $main = null;      // the adopted main flow

    // The hand-off marker: set right before a deliberate switch into a
    // dequeued coroutine. The flow that regains control reads it to tell
    // "my own turn came" (stop scheduling, return from the suspension)
    // from "a coroutine finished or parked into me" (keep draining).
    private ?MyCoroutine $handoff = null;

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

        while (true) {
            while (!$this->microtasks->isEmpty()) {
                ($this->microtasks->dequeue())();    // one tick of microtasks
            }

            if ($this->ready->isEmpty()) {
                if ($self === $this->main) {
                    break;                           // everything ran dry: main returns
                }                                    // (a real scheduler blocks in the
                                                     // reactor here instead)

                // A dry queue in a coroutine: park by waking the main loop,
                // which keeps draining. Control comes back only on a
                // deliberate wake; then return from the suspension.
                $this->main->continuation->switchTo();
                break;
            }

            $current = $this->ready->dequeue();

            if ($current === $self) {
                break;                               // a self-resume: the flow yielded
            }                                        // and its own turn came

            $current->continuation ??= ($this->createContinuation)($current->body);

            $error = $current->pendingError;
            $current->pendingError = null;

            try {
                // A pending error is thrown at the suspension point inside;
                // an adopted fiber is driven with resume()/throw() instead.
                $this->handoff = $current;           // a deliberate wake: its turn came
                $current->continuation->switchTo(null, $error);
            } catch (\Throwable $unhandled) {
                // The body finished with an uncaught exception: it surfaces
                // here, at the switch site. Record it on the coroutine.
                $current->unhandledException = $unhandled;
            }

            if ($this->handoff === $self) {
                break;                               // this flow was dequeued while it
            }                                        // was scheduling: stop and resume it

            // $current finished or parked: keep draining.
        }

        $this->handoff = null;
        return $self;    // this frame resumes when $self runs again: record it
    }

    public function onFiber(\Fiber $fiber): ?object
    {
        // Adopt every foreign fiber (this scheduler creates none of its own).
        return new MyCoroutine($fiber);
    }

    public function onDefer(callable $task): void
    {
        $this->microtasks->enqueue($task);
    }

    public function onWaitInfo(object $coroutine, string $info): void
    {
        $this->waitInfo[$coroutine] = $info;         // introspection, deadlock reports
    }

    public function onShutdown(): void
    {
        // Policy decision: stop accepting work, then cancel or drain the rest.
    }
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

One control-flow rule deserves emphasis, because omitting it is the classic bug of a distributed
loop: control returning from `switchTo()` has two distinct meanings. Either this flow was itself
dequeued (its turn came: stop scheduling and return from the suspension), or the coroutine it
switched into finished or parked (keep draining; only the main flow is woken this way). The
`$handoff` marker tells the two apart. Without it, a flow frozen in the middle of its own loop is
scheduled past and lost: it holds a live stack that nothing will ever resume. The reference C
scheduler ([ext/test_scheduler](https://github.com/true-async/php-src/tree/async-core/ext/test_scheduler))
hit exactly this in its first test run; the loop above is the corrected shape it converged on.

### Hook specification

#### `SchedulerHook::register(string $module, callable $factory): void`

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
initialisation belongs in its constructor. Every failure (a second registration, a factory that
throws or returns the wrong type, an engine-level refusal) is an `Error`; the method reports
nothing through a return value.

#### `onEnqueue(object $coroutine, ?Throwable $error = null): bool`

Make a coroutine runnable and place it in the run queue. Enqueuing a fresh coroutine and resuming
a suspended one are the same operation. A non-null `$error` is raised at the coroutine's
suspension point, which is how cancellation and IO/timeout failures reach waiting code; the
scheduler delivers it with the `$error` parameter of `switchTo()` (see "Exceptions and value
transfer"). The return value is data, not a success status: `true` means the coroutine is queued
and will run; `false` means it was not accepted (for example during shutdown): a quiet
rejection, not an error. What happens next depends on the caller: at a PHP-visible boundary the
engine converts the rejection into a thrown `Error` (e.g. `Fiber::resume()` on an adopted fiber);
a C caller such as a reactor callback observes the `false`, disposes of the error it was
delivering and treats the coroutine as never scheduled.

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

#### `onDefer(callable $task): void`

Queue a one-shot microtask; the scheduler runs it on its next tick. The PHP engine never stores tasks:
both `SchedulerHook::defer()` and C-level callers route here, and the queue lives in the scheduler.
A scheduler that cannot accept the task throws; a silent `false` would lose the task with no one
noticing. What microtasks are for, and the concurrent-iterator pattern built on them, is shown in
"Microtasks in practice: a concurrent iterator" below.

#### `onWaitInfo(object $coroutine, string $info): void`

Lets the caller record what a coroutine is waiting for, as a human-readable string
(`"socket #7 (readable)"`, `"channel recv"`, …). May be called more than once if the coroutine is
waiting on several events at once. The scheduler stores each description against the coroutine
for introspection tooling and deadlock reports; the call carries no scheduling effect.

#### `onShutdown(): void`

The hook fires right before the graceful shutdown phase begins, so the scheduler can clean up its
own state. The request is not tied to a fixed lifecycle point: it comes from whoever decides that
concurrency must end early — in the reference stack, `exit()` inside a coroutine triggers it
instead of tearing the request down mid-flight. From here the scheduler stops accepting new work
and decides the fate of the remaining coroutines: run them to completion, or cancel them by
enqueuing with an error.

Cancelling is not the same as dropping. A suspended coroutine holds a live stack (its VM frames
and everything they reference), and simply releasing the object leaks it. The scheduler must wake
the coroutine one last time with a termination error through the usual channel, the engine's
unwind-exit is made for this, so the stack unwinds: `finally` blocks run, frame-held resources
are released. The same duty applies at the end of the request to coroutines still parked with
nobody left to resume them.

### The coroutine context

A coroutine needs memory of its own. Everything built on top of the scheduler depends on it:
frameworks keep the request id, DI scopes and transaction state per flow, and many PHP functions
keep state that used to be safely global and becomes per-coroutine the moment flows interleave.
Without it, nothing above the scheduler works. And it is a hot path: output buffering resolves
its handler stack on every byte printed, orders of magnitude more often than any context switch
occurs. Both facts point the same way: the context is not scheduling policy to route through
hooks, but engine machinery. It lives directly in the engine's coroutine structure and is
accessed at C speed, with no scheduler involvement.

The engine owns two such stores per coroutine. The first is the **internal context**, reserved for the engine
and C extensions. Keys are process-unique numeric ids, allocated once per process from a static
C-string name; C code reads and writes values through three operations (find/set/unset, taking
the current or an explicit coroutine), and the store dies with the coroutine. The internal
context is structurally inaccessible from PHP, and that is the point: its values are raw C data
(the worked examples in [scheduler_rfc_examples.md](https://github.com/true-async/php-async-core-rfc/blob/main/scheduler_rfc_examples.md) store bare pointers), and
if they lived in PHP-visible storage, ordinary PHP code could overwrite a pointer or unset an
entry whose memory C code still owns, corrupting C state. The boundary is enforced by
construction rather than by convention.

The **userland** context (string/object keys: request id, tracing span, locale) is the second
store, and it is part of this contract for one reason: portability. Its consumers are libraries
(tracing, logging, DI scopes) that must reach the current flow's context without knowing which
scheduler is installed; if every scheduler exposed its own accessor, no such library could be
scheduler-agnostic. `Async\get_context()` returns the current flow's `Context` (main included,
whether or not a scheduler is registered), and `get_context($coroutine)` the store of a given
coroutine object; either way the store is created lazily and dies with its coroutine. The engine
provides storage and access, nothing more. A fresh coroutine starts with an empty context:
whether a child sees the spawner's values (a copy, a link, or nothing) is inheritance policy,
and that stays in the scheduler's user-facing API, together with `spawn()` and `await()`.

Both stores are implemented in the proof of concept, C extensions reaching the userland store
through `zend_async_context_find/set/unset`, and are covered by tests on both sides:
[Zend/tests/async](https://github.com/true-async/php-src/tree/async-core/Zend/tests/async)
(`context_*.phpt`: the schedulerless main store, per-coroutine isolation, survival of main's
values through adoption) and
[ext/test_scheduler/tests](https://github.com/true-async/php-src/tree/async-core/ext/test_scheduler/tests)
(tests 013 and 032-034: the internal context, isolation under a C scheduler, C/PHP
cross-visibility, lifetime and object-key ownership).

### The internal context in practice

Some functions need to keep state tied to a coroutine. An example is `ob_start()`: it pushes a
handler onto a stack, and with thousands of coroutines interleaving in one process, a single
process-global stack would mix their output. Moving the handler stack into the coroutine's
internal context fixes that without changing a line of userland code — and the same four-step
pattern (allocate a key, create state on first use, dispose it on the coroutine's finish event,
resolve through the current coroutine) applies to any core subsystem or extension with
process-global state to make coroutine-safe.

[scheduler_rfc_examples.md](https://github.com/true-async/php-async-core-rfc/blob/main/scheduler_rfc_examples.md) collects the worked examples with the actual code:
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
[scheduler_rfc_examples.md](https://github.com/true-async/php-async-core-rfc/blob/main/scheduler_rfc_examples.md)
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

### Process forking

`fork()` and a live scheduler do not mix: coroutines parked on the reactor, watcher descriptors
and worker threads cannot survive a fork of the process. While a scheduler is active,
`pcntl_fork()` therefore throws an `Error` unconditionally; the same guard applies to any
extension that forks the request process.

The escape hatch is a C-level hook pair, not a PHP API. A C extension that knows how to survive
a fork (typically the scheduler itself, together with its reactor) registers `before_fork()`,
which runs in the parent and decides whether this particular fork is allowed (the reference
implementation permits it only while the main coroutine is the sole live one), and
`after_fork_child()`, which reinitialises the reactor in the freshly forked child. Without a
registered pair the engine's answer is simply "no". The exact C interface is documented in
[SCHEDULER.md](SCHEDULER.md).

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
  from PHP code.
- **To `pcntl_fork()`:** it now throws while a scheduler is active (see "Process forking"). A
  forked child cannot inherit a working copy of the reactor's state (see
  [reactor.md §12](https://github.com/true-async/php-async-core-rfc/blob/main/reactor.md)); a
  C-level fork-hook pair lets the scheduler permit specific cases (the reference implementation
  allows forking only while the main coroutine is the sole coroutine running).
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
  Includes the full context implementation: `Async\Context`, `Async\get_context()` and the
  `zend_async_context_*` C API, with tests in
  [Zend/tests/async](https://github.com/true-async/php-src/tree/async-core/Zend/tests/async).
- Test scheduler: https://github.com/true-async/php-src/tree/async-core/ext/test_scheduler,
  an in-tree C scheduler (the C twin of the MiniScheduler above) filling every ABI slot from a
  separate Zend extension, with the .phpt suite exercising the hooks end to end. Built only with
  `--enable-test-scheduler`, activated by `test_scheduler.enable=1`.
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
- [scheduler_rfc_examples.md](https://github.com/true-async/php-async-core-rfc/blob/main/scheduler_rfc_examples.md): worked examples with real code —
  per-coroutine contexts (`ob_start()` buffering, `gethostbyname()`) and the microtask-driven
  concurrent iterator.
- [ext/test_scheduler](https://github.com/true-async/php-src/tree/async-core/ext/test_scheduler):
  the in-tree test scheduler; the runtime proof that the C ABI is implementable from a separate
  extension.

## Rejected Features

None yet.

## Changelog

- **0.4**: the userland context gains a standard PHP API: `Async\Context` (find/has/set/unset,
  string/object keys) and `Async\get_context(?object $coroutine = null)`, so context-consuming
  libraries stay scheduler-agnostic. Storage and access are engine-owned and per-coroutine;
  inheritance policy remains with the scheduler's user-facing API. The internal context stays
  C-only, unchanged. The scheduler loops (the reactor sketch and the MiniScheduler) gain the
  hand-off rule and park-to-main, fixing a lost-flow bug the in-tree test scheduler
  (ext/test_scheduler, the new runtime validation of the ABI) uncovered; the shutdown section now
  states that cancelled coroutines must be unwound, not dropped. The context API is implemented
  in the proof of concept (engine, PHP bridge and the test scheduler), tests on both the PHP and
  the C side.
- **0.3**: the context leaves the hooks. The internal context moves into the engine's coroutine
  structure (engine-owned storage behind the C macros: it is a hot path, and coroutine-local
  memory is what everything above the scheduler depends on); the userland context joins
  `spawn()`/`await()` in the scheduler's user-facing API, outside this contract. The `Scheduler`
  interface shrinks to the six event hooks.
- **0.2**: one error channel for the PHP hooks: failures are exceptions, `bool` returns remain
  only where `false` is data (`onEnqueue`: not accepted, `contextUnset`: key existed);
  `register()` returns void and throws on every failure. `Continuation::switchTo()` gained an
  `$error` parameter, and the full value/exception transfer contract is specified in the new
  "Exceptions and value transfer" section. New "Process forking" section: `pcntl_fork()` throws
  while a scheduler is active, with a C-level fork-hook pair as the escape hatch.
- **0.1**: initial draft.
