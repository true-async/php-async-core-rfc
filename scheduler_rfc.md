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
incompatible, and the engine has no seam through which it could ever drive any of them.

That gap was left open deliberately. Fibers were introduced as a low-level primitive, on the
explicit understanding that a higher-level scheduling layer would be built on top of them: first
in userland, and in time in the engine. This RFC takes that engine-level step: it follows a
direction the Fibers proposal already left room for, rather than inventing a new one.

**The purpose of this RFC is to give PHP the ability to activate a concurrent execution mode.**
The proposal draws on the implementation experience of the
[TrueAsync project](https://github.com/true-async), a complete concurrency stack for PHP
(scheduler, libuv reactor, thread pool). TrueAsync demonstrated that PHP can be moved to
asynchronous execution *in full*: every blocking I/O function (file and socket operations, DNS,
streams, `sleep()`, …) becomes non-blocking transparently, without any change to existing code:
a coroutine that would block yields instead and lets others run. This RFC extracts the interface
that experience converged on. The engine learns to speak in coroutines, and the component that
drives them, the scheduler, becomes pluggable. A C extension or a PHP library hands the engine
a scheduler object in a single call, and from that point on PHP operates concurrently.

```php
// MyScheduler implements Async\Scheduler.
Async\SchedulerHook::register('my-scheduler', new MyScheduler());
```

## Scope: what this RFC deliberately does not define

This document defines only the *hook layer*: the set of hooks through which a scheduler extends
the behaviour of the PHP core, without baking any concrete Scheduler implementation into the
engine. **Extensions and third-party code remain free to define arbitrary functions, classes and
APIs on top of the registered scheduler** (`spawn()`, `await()`, channels, futures, an `Async\`
namespace), **and this RFC intentionally defines none of them.** The class of the coroutine
object, the transfer of values between coroutines, and the shape of the user-facing API are the
exclusive domain of the scheduler implementation. The
[True Async RFC](https://wiki.php.net/rfc/true_async) is one such API, built on this core.

This separation is deliberate. The engine standardises *how concurrency is activated and which
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
   engine instead of each driving concurrency in isolation, while a plain fiber keeps its
   existing behaviour.
5. **Direct switching between execution contexts.** The engine exposes a low-level symmetric-switch
   primitive, `Continuation`: a scheduler can transfer control directly from one coroutine into
   another (`$continuation->switchTo()`) instead of routing every hand-off through a central loop.
   This gives schedulers a symmetric coroutine model, built on the engine's own fiber machinery.

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
or unhandled exception, cancellation, and its execution-flow context. The engine and the hooks
speak in coroutines; which primitive backs a given coroutine (a fiber or a continuation) is the
scheduler's implementation choice.

Two orthogonal attributes may additionally apply: *cancelled* (cancellation has been requested)
and *main* (the coroutine that wraps the top-level script). Each coroutine records its
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
 * is done on the Continuation itself. Internally backed by the engine's Fiber
 * machinery, so it stays compatible with fiber-aware tooling (e.g. Xdebug).
 */
final class Continuation
{
    /** Switch control into this continuation, optionally sending $value. */
    public function switchTo(mixed $value = null): mixed {}
}

/**
 * A scheduler implements this interface and hands an instance to
 * SchedulerHook::register(). The `on*` methods are *event callbacks*: the engine
 * decides when to invoke them, the hooks decide which coroutine runs next, and
 * the low-level stack switch itself is always performed by the engine's fiber
 * machinery (behind Continuation::switchTo() and the Fiber API).
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
    /**
     * The scheduler starts. The engine hands a PHP scheduler its privileged
     * capabilities as plain closures (a C scheduler reaches the primitives
     * directly and receives none). Because they arrive only here, only the
     * scheduler holds them: no other code can mint or capture continuations, or
     * read the current coroutine. Switching is not one of these closures: it is
     * done either through the plain Fiber interface (for an adopted fiber) or on
     * the Continuation itself (for the scheduler's own coroutines). Returns the
     * coroutine now current (or null); the engine records it, same as onSuspend().
     */
    public function onLaunch(
        \Closure $createContinuation,   // createContinuation(callable $entry): Continuation
        \Closure $currentContinuation,  // currentContinuation(): Continuation
        \Closure $currentCoroutine,     // currentCoroutine(): ?object
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
     * records it as the current coroutine, exactly as it does for onLaunch();
     * it never chooses one on its own. `$fromMain` marks the end-of-main
     * handover; `$isBailout` an abnormal termination of the main flow (a fatal
     * error): the last opportunity to clean up resources.
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
     * Registers a scheduler and activates the concurrent mode. A scheduler is
     * registered exactly once per process: if one is already registered,
     * whether by a C extension or by an earlier call, this method throws an
     * Error.
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
  `$this` and is type-checked at compile time, following existing engine
  integration points such as `SessionHandlerInterface`. Later revisions can add
  optional companion interfaces (the way session handlers gained
  `SessionUpdateTimestampHandlerInterface`) without breaking implementations.

- **Privileged operations as hidden closures.** `createContinuation`, `currentContinuation`
  and `currentCoroutine` are handed to `onLaunch()` once, only to the scheduler: a
  capability, not a global function or static method that any code could call.

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
scheduler reports it through the return values of `onLaunch()` and `onSuspend()`, and reads it
back through the `currentCoroutine` closure it was handed at `onLaunch()`. How the coroutine is
then exposed to userland (a `current()` accessor, a coroutine class, `spawn()`/`await()`) is
**not part of this RFC**: like the coroutine object itself, it belongs to the scheduler's API.

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

One flow exists before the scheduler ever runs: the top-level script itself. The engine starts
it, not the scheduler, so at first it is the only schedulable flow without a coroutine object.
The system normalises itself at the first yield: `onSuspend()` captures the currently running
context with the `currentContinuation` capability, wraps it into the scheduler's own coroutine
object, and marks it *main* (the attribute from the Coroutines section). From that point the
top-level script is suspended, enqueued and resumed like any other coroutine; nothing in the
scheduler treats it specially afterwards.

This normalisation also pins down the return value of `onSuspend()` precisely. The call returns
when something switches back into the yielding flow, and the flow running at that moment is
exactly the one the hook normalised: so the scheduler returns that coroutine object, and the
engine records it as current. In particular, the end-of-main handover returns the *main*
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
    private \Closure $createContinuation;   // capability received at onLaunch()
    private \Closure $currentContinuation;  // capability received at onLaunch()
    private \Closure $currentCoroutine;     // capability received at onLaunch()

    public function onLaunch(
        \Closure $createContinuation,
        \Closure $currentContinuation,
        \Closure $currentCoroutine,
    ): ?object {
        // The capabilities arrive exactly once: from here on, only the
        // scheduler can mint or capture continuations, or read the current
        // coroutine.
        $this->createContinuation = $createContinuation;
        $this->currentContinuation = $currentContinuation;
        $this->currentCoroutine = $currentCoroutine;
        $this->ready = new \SplQueue();
        $this->microtasks = new \SplQueue();
        $this->waitInfo = new \SplObjectStorage();
        return null;                                 // nothing is running yet
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
```

Three things are worth noticing. The engine never sees a queue, a policy or a coroutine class:
it only fires the hooks and records what `onLaunch()`/`onSuspend()` return. The user-facing API
(`spawn()` here) is ordinary code the scheduler adds on top. And replacing the naive loop with
the reactor version from the previous section turns this sketch into a real event-driven
scheduler without changing any signature.

### Hook specification

#### `onLaunch(Closure $createContinuation, Closure $currentContinuation, Closure $currentCoroutine): ?object`

Called once when the scheduler starts: for a C scheduler just before the script runs, for a PHP
scheduler at registration (script code is already running). The engine hands a PHP scheduler its
privileged capabilities as closures: `createContinuation(callable): Continuation` mints a
continuation, `currentContinuation(): Continuation` captures the context that is currently
running (how the main flow is adopted, see above), and `currentCoroutine(): ?object` returns the
coroutine the engine records as running. Switching is not one of them: it is done either through
the plain Fiber interface (for an adopted fiber) or on the Continuation itself
(`$continuation->switchTo()`, for the scheduler's own coroutines). State initialisation belongs
here; returns the coroutine now current, or null.

#### `onEnqueue(object $coroutine, ?Throwable $error = null): bool`

Make a coroutine runnable and place it in the run queue. Enqueuing a fresh coroutine and resuming
a suspended one are the same operation. A non-null `$error` is raised at the coroutine's
suspension point, which is how cancellation and IO/timeout failures
reach waiting code. `false` means the coroutine was not accepted (for example during shutdown).

#### `onSuspend(bool $fromMain, bool $isBailout): ?object`

The central scheduling hook: the current flow yields. The scheduler first normalises the caller:
on its first yield the main flow has no coroutine object yet, so the scheduler captures it with
`currentContinuation`, wraps it and marks it *main* (see "The main flow is a coroutine too"). It
then selects the next runnable coroutine and switches into its Continuation. The call returns
when something switches back into the yielding flow; the scheduler returns that flow's coroutine
object, and the engine records it as the current coroutine. `$fromMain` marks the end-of-main
handover: instead of a single switch, the scheduler drains the remaining coroutines, and the
hook returns the *main* coroutine. `$isBailout` accompanies it when
the main flow terminated abnormally (`exit()`, a fatal error); in that case the scheduler may
discard the remaining work instead of completing it. Note that this call can arrive while the
engine is already terminating after a fatal error. It is the scheduler's last opportunity to
clean up: release connections and cancel the remaining coroutines so that their cleanup
handlers run before the request is torn down.

#### `onFiber(Fiber $fiber): ?object`

The point where the engine offers a starting `Fiber` for adoption. Called on every
`Fiber::start()` while a scheduler is active. Return a coroutine to bind to the fiber (its
`suspend()`/`resume()` then route through the scheduler); return `null` to leave it a plain
low-level fiber. When the hook is absent, every fiber stays low-level.

This matters because existing frameworks (ReactPHP/Revolt, AMPHP) are themselves built on fibers:
the fiber is the low-level primitive their own event loop drives. If the engine adopted every
fiber, such a scheduler would recurse into itself. So the scheduler decides per fiber: it keeps
references to the fibers it created for itself and returns `null` for those, a coroutine for the
rest. The engine places no flag on the fiber and tracks nothing; the private set lives in the
scheduler, so no outside code can mark a fiber "internal". Unlike the other hooks, this one
receives a real `Fiber` rather than a coroutine, because the fiber has not been adopted yet.

#### `onDefer(callable $task): bool`

Queue a one-shot microtask; the scheduler runs it on its next tick. The engine never stores tasks:
both `SchedulerHook::defer()` and C-level callers route here, and the queue lives in the scheduler.
What microtasks are for, and the concurrent-iterator pattern built on them, is shown in
"Microtasks in practice: a concurrent iterator" below.

#### `onWaitInfo(object $coroutine, string $info): bool`

Whoever suspends a coroutine may describe *what it is waiting for* in a human-readable string
(`"socket #7 (readable)"`, `"channel recv"`, …). This hook hands that description to the scheduler,
which stores it against the coroutine for introspection tooling and deadlock reports. It carries
no scheduling effect.

#### `onShutdown(): bool`

A graceful shutdown has been requested. This is not a fixed lifecycle point: the request comes
from whoever decides that concurrency must end early. In the reference stack, for example,
`exit()` inside a coroutine requests a graceful shutdown instead of tearing the request down
mid-flight. The scheduler stops accepting new work and decides the fate of the remaining
coroutines: run them to completion, or cancel them by enqueuing with an error.

#### `getContext(object $coroutine): object` / `getInternalContext(object $coroutine): object`

Each coroutine carries two key/value contexts. The **userland** context uses string/object keys
(request id, tracing span, locale); the **internal** context is a separate store reserved for C
extensions, keyed by process-unique numeric keys. These getters return the context object, which
is read and written through the operations below. Whether a context is inherited along spawn
chains is the scheduler's policy, not part of this contract.

The two stores are separate for safety, not convenience. Internal-context values are raw C data
(the worked example below stores a bare pointer), addressed by numeric keys that PHP code cannot
even name. If C-extension state lived in the userland context, ordinary PHP code could reach it
through the same context operations it uses for its own keys: overwrite a pointer, unset an
entry whose memory C code still owns, and thereby corrupt C state or silently change core
behaviour. The internal context is therefore structurally inaccessible from PHP; the boundary is
enforced by construction rather than by convention.

#### `contextFind(object $context, mixed $key): mixed` / `contextSet(...): bool` / `contextUnset(...): bool`

Read, store, and remove values in a context. Keys are strings or objects (compared by identity).
The internal context is operated on by C extensions directly, not through PHP.

### The internal context in practice: output buffering

The clearest illustration of the internal context is output buffering in the TrueAsync engine
tree. Output buffering is stateful: `ob_start()` pushes a handler onto a stack, and everything
printed afterwards lands in that buffer. With thousands of coroutines interleaving in one
process, a single process-global stack would mix their output. The internal context solves this
without changing a line of userland code.

At startup, the output subsystem allocates its process-unique numeric key once:

```c
/* main/output.c (TrueAsync engine tree, abridged) */
uint32_t php_output_context_key = 0;

static void php_output_async_init(void)
{
    if (php_output_context_key == 0) {
        php_output_context_key = ZEND_ASYNC_INTERNAL_CONTEXT_KEY_ALLOC("php_output_context");
    }
}
```

When `ob_start()` runs while a coroutine is current, the subsystem looks up that coroutine's own
handler stack in the internal context, lazily creating it on first use:

```c
static php_output_context_t *php_output_ensure_coroutine_context(zend_coroutine_t *coroutine)
{
    zval *found = ZEND_ASYNC_INTERNAL_CONTEXT_FIND(coroutine, php_output_context_key);
    if (found != NULL) {
        return Z_PTR_P(found);
    }

    php_output_context_t *ctx = ecalloc(1, sizeof(*ctx));
    php_output_init_async_context(ctx);

    zval stored;
    ZVAL_PTR(&stored, ctx);
    ZEND_ASYNC_INTERNAL_CONTEXT_SET(coroutine, php_output_context_key, &stored);

    /* released by a callback on the coroutine's finish event */
    return ctx;
}
```

From that point, every output operation resolves the handler stack through the current
coroutine, so two coroutines that both call `ob_start()` buffer independently and never
interleave. The C macros used here are exactly the operations this RFC defines as hooks: they
resolve to the registered scheduler's `getInternalContext()` / `contextFind()` / `contextSet()`
implementation, whether that scheduler is written in C or in PHP. This is also the pattern the
*RFC Impact* section refers to: any extension can keep per-coroutine state the same way, keyed
by its own allocated key, with cleanup tied to the coroutine's lifecycle.

Output buffering is not the only core subsystem that lives this way:
[context_examples.md](context_examples.md) collects the worked examples, including
`gethostbyname()`, whose traditional static result buffer becomes per-coroutine state through
the same four-step pattern.

### Microtasks in practice: a concurrent iterator

A microtask is the scheduler's smallest unit of work: a callable that runs inside the tick,
between coroutine switches. It has no stack of its own and never suspends; it runs to completion
right where the scheduler stands. That makes it far cheaper than a coroutine, and the right tool
when logic must execute at scheduling points but does not itself wait: bookkeeping, waking
sleepers, and incremental algorithms sliced across ticks.

The classic use is a concurrent iterator, and the engine's own pattern is worth copying
precisely. The iteration state is shared. A worker coroutine drives a plain loop over it; the
handler it calls is ordinary code and may suspend at any point. The microtask is the watchdog:
it can only fire while the worker is parked (a running coroutine holds the thread until it
yields), so when it does fire, it spawns a replacement worker over the same state, which becomes
the new owner of the loop. When the old worker eventually resumes, it sees that it no longer
owns the iteration and exits immediately. Iteration never stalls behind one slow element, and
exactly one coroutine drives the loop at a time:

```php
// Pseudocode: the engine's concurrent-iteration shape in PHP. spawn() and
// currentCoroutine() are the scheduler's helpers, as in the earlier sections.
final class ConcurrentIterator
{
    private ?object $owner = null;      // the coroutine currently driving the loop
    private bool $done = false;

    public function __construct(
        private \Iterator $items,
        private \Closure $handler,
    ) {}

    public function start(): void
    {
        Async\SchedulerHook::defer($this->tick(...));
    }

    /** The microtask: it fires only while the current worker is parked. */
    private function tick(): void
    {
        if ($this->done) {
            return;                     // finished: stop re-arming
        }

        $this->owner = spawn($this->run(...));        // replacement takes the state over
        Async\SchedulerHook::defer($this->tick(...)); // re-arm for the next tick
    }

    /** The loop a worker runs. The handler may suspend anywhere. */
    private function run(): void
    {
        $me = currentCoroutine();

        while (!$this->done && $this->items->valid()) {
            $item = $this->items->current();
            $key = $this->items->key();
            $this->items->next();       // advance before the handler, like foreach

            ($this->handler)($item, $key);   // may suspend: the microtask then
                                             // staffs a replacement worker
            if ($this->owner !== $me) {
                return;                 // someone took over while we slept:
            }                           // exit at once, exactly one driver
        }

        $this->done = true;             // finished while still the owner
    }
}
```

This is exactly how the engine runs object destructors during GC in concurrent mode.
`gc_destructors_coroutine()` re-arms the microtask and iterates the destructor buffer; if a
destructor suspends, the next tick's microtask (`zend_gc_destructors_coroutine_microtask()`)
spawns a fresh destructor coroutine that continues from the shared index and becomes the owner,
and the displaced one exits through the identity check
(`GC_G(dtor_coroutine) != ZEND_ASYNC_CURRENT_COROUTINE`); see
[zend_gc.c](https://github.com/true-async/php-src/blob/true-async/Zend/zend_gc.c). TrueAsync's
general-purpose concurrent iterator
([iterator.c](https://github.com/true-async/php-async/blob/main/iterator.c)) generalises the
same shape: the iterator structure is itself the microtask, and it staffs worker coroutines up
to a configurable concurrency limit over one shared position.

### Engine invocation points

Once registered, the scheduler is **always active**: there is no lazy initialisation and no
implicit start on the first asynchronous call.

- **Launch.** A C-registered scheduler launches immediately before the script code runs. A
  PHP-registered one launches inside `register()` itself, because the engine's own launch point
  has already passed by the time userland code executes.
- **End of main.** When the main script ends, the engine hands control to the scheduler with
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

Next minor PHP 8.x.

## RFC Impact

- **To SAPIs:** none observable. CLI, FPM and phpdbg gain the invocation points described above,
  all inactive without a registered scheduler.
- **To Existing Extensions:** none by default. Extensions requiring async awareness receive a
  dedicated internal per-coroutine context keyed by process-unique numeric keys, inaccessible
  from PHP code.
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
  engine's blocking functions transparently yield when a scheduler is active.
- **Threads.** A native threading / parallelism model that cooperates with the scheduler.
- **Connection pooling (PDO Pool).** A shared, coroutine-aware connection pool (for example for
  PDO) that reuses database connections across coroutines and requests.

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
  the queueing model and worked example behind the ecosystem-role section.
- `Io\Poll` (`main/php_poll.h`): the readiness-multiplexing API in php-src master, suitable as the
  IO source for a userland event loop.
- [SCHEDULER.md](SCHEDULER.md): the exact engine invocation points, for implementers.
- [context_examples.md](context_examples.md): how PHP core uses the per-coroutine contexts
  (`ob_start()` buffering, `gethostbyname()`), and why the internal context is isolated from
  userland.

## Rejected Features

None yet.

## Changelog

- **0.1**: initial draft.
