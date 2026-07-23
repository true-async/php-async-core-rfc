# PHP RFC: Concurrency Support in the PHP Engine

- **Version:** 0.9
- **Date:** 2026-07-22
- **Author:** Edmond, edmondifthen@proton.me
- **Status:** Draft
- **Implementation:** https://github.com/true-async/php-src/tree/async-core
- **Pull request:** https://github.com/php/php-src/pull/22561
- **Discussion thread:** tbd
- **Voting thread:** tbd

## Introduction

PHP has no engine-level way to run code concurrently. Fibers (PHP 8.1) added the low-level
primitive, cooperative context switching, but no scheduler: deciding what runs, when, and in which
order was left entirely to userland. As a result, each framework maintains its own event loop, its
own coroutine abstraction and its own conventions. These implementations are mutually
incompatible, and the engine has no seam through which it could drive any of them.

That gap was left open deliberately. Fibers were introduced as a low-level primitive for
higher-level abstractions to build on, first in userland; the Fibers RFC left an engine-level
event loop to a future RFC. This RFC takes that engine-level step.

**The purpose of this RFC is to give PHP the ability to activate a concurrent execution mode.**
The engine gains a coroutine representation, and the component that drives coroutines, the
scheduler, becomes pluggable. An extension supplies the scheduler, and from that point on PHP operates
concurrently.

Throughout this document a "flow" means a logical flow of execution, never an OS thread.
Everything described here happens inside a single OS thread. Nothing in this proposal introduces
parallelism, shared-memory threading, or any change to ZTS. Interleaving is cooperative, and only
one flow runs at a time.

This RFC adds no classes, no functions, no constants and no syntax. The engine compiles in no PHP
symbols at all. With no scheduler registered, PHP behaves exactly as it does today.

An RFC whose changes are internal to the engine is an established form; recent examples are the
[Polling API](https://wiki.php.net/rfc/poll_api) for PHP 8.6 and the
[IR-based JIT](https://wiki.php.net/rfc/jit-ir) for PHP 8.4.

## Scope: what this RFC deliberately does not define

This document defines only the points at which the engine and a scheduler meet: the notifications
the engine raises, and the operations it grants in return. It builds no concrete scheduler into the
engine, and it defines no name visible to PHP code.

**Extensions and third-party code remain free to define arbitrary functions, classes and APIs on
top of the registered scheduler** (`spawn()`, `await()`, channels, futures, an `Async\` namespace),
**and this RFC intentionally defines none of them.** The class of the coroutine object, the
transfer of values between coroutines, and the shape of the user-facing API are the exclusive
domain of the scheduler implementation. The [True Async RFC](https://wiki.php.net/rfc/true_async)
is one such API, built on this core.

The same applies to activation from PHP. A bridge extension that lets a scheduler be written in
plain PHP is possible and exists, but it is an extension like any other and is not part of this
proposal. See "Design rationale: why the engine defines no PHP-level hooks".

This separation is deliberate. The engine standardizes how concurrency is activated and which
component is in charge, while the ecosystem retains full freedom over how concurrency is presented
to the user.

## Goals

1. **A single activation contract.** One registration point removes the need for libraries to
   depend on a specific event-loop implementation.
2. **Implementable from outside the engine.** Everything a scheduler needs is reachable by an
   ordinary extension, with no further engine changes. The in-tree reference scheduler fills every
   slot from a separate Zend extension and is the runtime proof of this.
3. **Strict opt-in.** With no scheduler registered, PHP behaves exactly as it does today, at
   negligible cost.
4. **Backward-compatible fiber adoption.** Existing `Fiber`-based code keeps running unchanged.
   When a scheduler is active, the fiber notification lets it adopt each starting fiber onto its
   schedule, and a fiber it declines keeps its existing behavior. Through adoption, fiber-based
   libraries such as ReactPHP, Revolt and AMPHP can run on the engine's scheduler instead of each
   driving concurrency on its own.
5. **Direct switching between coroutines.** The granted switch operation transfers control from
   one coroutine into another instead of routing every handoff through a central loop. The switch
   is symmetric and built on the engine's own fiber machinery.

## Proposal

### Scheduler ABI

The engine and the scheduler exchange two things: coroutines, and control transferred between
them. This RFC adds the mechanism on which an implementation of coroutines can later be built,
and with it brings symmetric flows of execution into the engine.

A coroutine is a lightweight unit of execution: a callable with a defined lifecycle.

> created, queued, running, suspended, finished

The middle of the chain is a cycle rather than a straight line. A suspended coroutine re-enters
the queue when it is resumed, and the queued, running, suspended cycle repeats until the callable
returns or throws.

A coroutine sits at a higher level of abstraction than the execution context behind it. The
context is the saved stack that a switch restores, and it is engine-internal machinery keyed by
the coroutine object. The coroutine is the schedulable unit on top of it, adding the lifecycle, a
result or unhandled exception, and cancellation. The engine, the notifications and the granted
operations all work in terms of coroutines. No lower-level primitive is exposed.

Two orthogonal attributes may additionally apply: *canceled*, meaning cancellation has been
requested, and *main*, meaning the coroutine that wraps the top-level script. Each coroutine
records its completion result or unhandled exception, the source location at which it was spawned,
and, while suspended, descriptions of what it is waiting for. Those awaiting-info registrations
are attached by the code that suspends the coroutine and are wiped as a whole when it is enqueued
again.
Awaiting info is a diagnostics seam for introspection and deadlock reports, available to C code
only.

At the PHP level a coroutine is an opaque object. This RFC does not define its class; the
registered scheduler does.

Symmetric switching is the second half of the mechanism. A `Fiber` is asymmetric: it yields only
to its resumer, so going from A to B costs two switches through an intermediary. The switch
operation granted to the scheduler is symmetric, so A goes to B directly. This halves the number
of switches on the paths where they are frequent, such as channels, generators and pipelines.

### Engine notification points

The engine raises six notifications. It performs no scheduling of its own; every scheduling
decision comes back through a notification's return value.

Each notification below ends with a sketch of a scheduler's handler. `MyCoroutine` stands for the
scheduler's own coroutine class, and the granted operations from the next section appear as
closures the scheduler holds, such as `$this->switchTo` and `$this->currentCoroutine`. Handler
and helper names are illustrative, not part of this RFC.

#### Launch

**When:** the scheduler starts. A scheduler registered from C launches immediately before the
script's first line; one registered during the script, through a bridge extension, launches at its
registration point.
**Receives:** nothing.
**Returns:** the coroutine the top-level script runs in.
**Engine guarantees:** it marks the returned coroutine *main*, records it as current, and binds
its own execution context to it, so switching into that coroutine resumes the script at its
suspension point.
**Scheduler must:** return one of its own coroutine objects, constructed and ready to be recorded.
**Errors:** returning anything that is not a coroutine object is an `Error`. Without a main
coroutine there is no flow to run the script in.

```php
public function onLaunch(): object
{
    // The coroutine the top-level script runs in, from its first opcode.
    return $this->main = new MyCoroutine();
}
```

#### Suspend

**When:** a flow calls the engine's suspend entry point, handing control to the scheduler.
**Receives:**
- `fromMain` (boolean): the main coroutine has finished. The script's code is over, and whatever
  runs afterward, such as shutdown functions and destructors, is a different flow.
- `isBailout` (boolean): the main flow terminated abnormally, through a fatal error. This call can
  arrive while the engine is already terminating.

**Returns:** the coroutine that is running when control comes back. The engine records it as
current. The launch and suspend return values are the only way the engine learns which coroutine
is current; it never chooses one itself.
**Engine guarantees:** the call returns when something switches back into the yielding flow.
**Scheduler must:** either switch into another runnable coroutine or wait for events. When
`fromMain` is set, drain the remaining coroutines and return a fresh main coroutine rather than
the finished one. When `isBailout` is set, it may discard the remaining work instead of completing
it; this is its last chance to release resources.
**Errors:** returning the finished main coroutine, or a non-object, is an `Error`.

```php
public function onSuspend(bool $fromMain, bool $isBailout): object
{
    $self = ($this->currentCoroutine)();

    $this->runReadyCoroutines($self);     // switch through the queue until this
                                          // flow's own turn comes back

    return $fromMain
        ? $this->main = new MyCoroutine() // the finished main is replaced
        : $self;
}
```

#### Enqueue

**When:** a coroutine is to be made runnable. Creating a fresh coroutine and resuming a suspended
one are the same operation.
**Receives:**
- the coroutine.
- an optional error (throwable) to be raised at the coroutine's suspension point. This is how
  cancellation, timeouts and I/O failures reach waiting code.

**Returns:** boolean. `true` means the coroutine is queued and will run. `false` means it was not
accepted, for example during shutdown.
**Engine guarantees:** the engine itself does not act on the returned value; the caller observes
it. At a PHP-visible boundary the engine converts a rejection into a thrown `Error`, for instance
on `Fiber::resume()` against an adopted fiber.
**Scheduler must:** deliver the error, when present, through the error parameter of the switch
operation.
**Errors:** a `false` return is a quiet rejection and not an error. A C caller such as a reactor
callback observes it, disposes of the error it was delivering, and treats the coroutine as never
scheduled.

```php
public function onEnqueue(object $coroutine, ?\Throwable $error = null): bool
{
    if ($this->shuttingDown) {
        return false;                     // quiet rejection; the caller observes it
    }

    $this->ready->enqueue([$coroutine, $error]);
    return true;                          // the error travels with the next switch
}
```

#### Foreign fiber

**When:** a `Fiber` created by application or third-party code starts, while a scheduler is active.
**Receives:** the fiber.
**Returns:** a coroutine to adopt the fiber onto the schedule, or nothing to leave it a plain
low-level fiber.
**Engine guarantees:** when the fiber is adopted, the engine owns its body and runs it like any
other coroutine. The fiber's operations become scheduler policy: `start()`, `resume()` and
`throw()` park their value or exception, enqueue the adopted coroutine, and yield to the
scheduler rather than switching into the fiber immediately.
**Scheduler must:** decide per fiber. A scheduler that creates fibers for its own use must
decline them, or it would recurse into itself; it recognizes them by keeping its own private set,
since the engine tracks nothing here and no outside code can mark a fiber as internal.
**Errors:** none defined.

```php
public function onFiber(\Fiber $fiber): ?object
{
    if ($this->ownFibers->contains($fiber)) {
        return null;                      // its own machinery stays low-level
    }

    return new MyCoroutine();             // adopt the application fiber
}
```

#### Defer

**When:** a one-shot task is queued to run at the next scheduling point.
**Receives:** the callable.
**Returns:** nothing.
**Engine guarantees:** the engine stores nothing. Every deferral routes here, whether queued by
engine code, by an extension, or by an extension on behalf of PHP code, and the queue lives in the
scheduler.
**Scheduler must:** run the task on its next tick.
**Errors:** a scheduler that cannot accept the task throws. A silent rejection would lose the task
unnoticed.

```php
public function onDefer(callable $task): void
{
    $this->deferred->enqueue($task);      // drained once per tick
}
```

#### Shutdown

**When:** concurrency must end early. This is not tied to a fixed point in the request lifecycle;
it is raised when a coroutine ends through `exit()`.
**Receives:** nothing.
**Returns:** nothing.
**Engine guarantees:** the notification is raised before the graceful shutdown phase begins.
**Scheduler must:** stop accepting new work and decide what happens to the remaining coroutines,
either running them to completion or canceling them by enqueuing with an error.
**Errors:** none defined.

```php
public function onShutdown(): void
{
    $this->shuttingDown = true;           // no new work is accepted
    $this->cancelRemaining();             // policy: cancel, or drain to completion
}
```

### Operations granted to the scheduler

Three operations are handed to the scheduler when it starts, and to nobody else. They are granted,
not published: they exist in no function table, so holding them is a privilege of being the
registered scheduler rather than an API available to any code.

#### Bind a body to a coroutine

**Receives:** one of the scheduler's own coroutine objects, and the callable to execute.
**Returns:** nothing.
**Constraints:** an adopted fiber's body belongs to the engine and cannot be rebound. A coroutine
cannot be rebound once it has a body.

#### Switch into a coroutine

**Receives:** the target coroutine, an optional value, and an optional error.
**Returns:** the value passed back by whichever flow later switches into the caller.
**Constraints:** the main coroutine, an adopted fiber and the scheduler's own coroutines all
switch through this one path. Passing both a value and an error is a `ValueError`, since there is
nowhere for the value to arrive. The transfer contract is specified in "Exceptions and value
transfer".

#### Read the current coroutine

**Receives:** nothing.
**Returns:** the coroutine the engine records as running, or nothing when there is none.
**Constraints:** the engine never chooses the current coroutine; it only reports what the launch
and suspend notifications last returned.

### Registration

A scheduler is registered once per process, and from that moment concurrency is active. There is
no lazy initialization and no implicit start on the first asynchronous call. Registering a second
scheduler is an `Error`, as is any other registration failure; nothing is reported through a
return value. With no scheduler registered, every notification above is inert.

### Design rationale: why the engine defines no PHP-level hooks

The obvious alternative was to expose the activation contract to PHP: a class to register a
scheduler with, an interface for the scheduler to implement, and enough surface for a scheduler to
be written in plain PHP. An earlier draft of this proposal did exactly that, in an `Async\`
namespace. It was removed, for four reasons.

**The engine compiles in no PHP symbols.** With nothing added to any namespace, there are no name
collisions, nothing in existing code can break, and there is no new surface for tools to learn.

**A way to activate from PHP is one possible implementation, not a property of the engine.**
Freezing it into the engine would fix names and signatures that practice has not yet tested, and
changing them afterward would require another RFC. Left to an extension, they can be revised as
experience accumulates.

**Production schedulers are written in C and do not need it.** A scheduler in plain PHP is
valuable for tests, verification and experimentation, which is a development concern rather than
something the engine must provide.

**Different providers may reasonably differ.** The engine owns the storage and the semantics; an
extension owns the names. Two providers can expose different surfaces over the identical engine
behavior, and neither has to win.

The same reasoning governs the execution context. Exposing it as an object would hand a switching
primitive to every holder of that object, so it stays engine-internal and switching is a granted
operation instead. And for the same reason the engine ships no scheduler and no event loop of its
own: those are policy, and policy belongs to the extension.

One decision does survive from the earlier draft. A coroutine's execution context is built on the
same `zend_fiber_context` the `Fiber` API uses, so step debugging and stack traces keep working
and fiber-aware tooling such as Xdebug continues to function.

### How a scheduler is used

The notifications are the seam where a scheduler plugs its implementation into the engine. The
engine raises them, and the scheduler supplies the behavior. Everything a user sees, such as
`spawn()`, `await()`, timers and channels, is ordinary code built on top of that seam.

A non-blocking `sleep()`, for instance, is a handful of lines. It remembers the running coroutine,
arms a timer on the Polling API to wake it after the delay, and yields, so the thread runs other
coroutines instead of blocking.

```php
// Pseudocode. The reactor and helper names are illustrative and are not part of this RFC.
function sleep(float $seconds): void
{
    $coroutine = currentCoroutine();                       // the coroutine now running
    Poll::addTimer($seconds, static fn () =>               // PHP's built-in Poll (reactor) API
        resume($coroutine));                               // wake it when the timer fires
    suspend();                                             // yield; other coroutines run
}
```

`Poll` is the engine's event API. `currentCoroutine()`, `resume()` and `suspend()` are the
scheduler's user-facing helpers, and `suspend()` routes through the suspend notification. This RFC
standardizes only the notifications underneath, not this surface.

The engine keeps track of which coroutine is currently running, but never chooses it. The
scheduler reports it through the return value of the suspend notification, and reads it back
through the granted operation. How the coroutine is then exposed to userland, whether through an
accessor, a coroutine class, or `spawn()` and `await()`, is not part of this RFC.

The same split applies to deferred tasks. The one-shot callback queue belongs to the scheduler,
not to the engine. The engine only forwards the callable; storage, draining and exact semantics
are the scheduler's policy.

### The scheduler and the reactor

The scheduler owns coroutines and a run queue. A reactor, meaning an event loop over the operating
system's descriptors, timers and signals, is what makes I/O non-blocking. They are two halves of
one loop and meet at exactly two points. The reactor itself is outside this RFC; its C-level
interface is a separate document,
[reactor.md](https://github.com/true-async/php-async-core-rfc/blob/main/reactor.md).

**A reactor callback wakes a coroutine.** A non-blocking operation arms an event on the reactor
and suspends the coroutine. When the event fires, the reactor's callback hands the coroutine back
to the scheduler through the enqueue notification, moving it from suspended to runnable. The
reactor never runs coroutine code; it only flips the coroutine to ready. This is the `sleep()`
above: its timer callback resumes the coroutine.

**When idle, the scheduler blocks in the reactor.** When the run queue drains, the scheduler does
not spin. From inside the suspend notification it asks the reactor to block until the next event.

```php
// Pseudocode: the scheduler's suspend handler, blocking in the reactor when idle.
public function onSuspend(bool $fromMain, bool $isBailout): object
{
    $self = ($this->currentCoroutine)();   // the yielding flow, main included

    while ($this->hasLiveCoroutines()) {
        if ($this->ready->isEmpty()) {
            Poll::run(block: true);        // sleep in the kernel until an event fires;
            continue;                      // its callback re-queues the woken coroutine
        }

        [$current, $error] = $this->ready->dequeue();

        if ($current === $self) {
            if ($error !== null) {
                throw $error;              // delivered at this flow's suspension point
            }
            break;                         // its own turn came: return from the suspension
        }

        $this->handoff = $current;         // mark the deliberate wake
        ($this->switchTo)($current, null, $error);

        if ($this->handoff === $self) {
            break;                         // $self was dequeued while it was scheduling
        }
    }

    return $self;                          // this frame resumes when $self runs again
}
```

Coroutines run until they all park on I/O, the queue empties, the scheduler blocks in the reactor,
an event fires a callback, the callback re-queues a coroutine, and the scheduler switches into it.
No coroutine is lost and the thread never busy-waits.

### The main flow is a coroutine too

The top-level script is a coroutine from its first opcode rather than a plain flow that becomes
one at its first yield. The launch notification fires when the scheduler starts, and the coroutine
it returns is the main flow: marked *main* and recorded as current before any script code runs.
The main coroutine borrows the engine's own execution context, meaning the OS-thread stack the
script already runs on, so switching into it resumes the script at its suspension point, like any
other coroutine.

Without this, every scheduler path would carry a special case for the main flow: switching,
cancellation, queue handling and introspection would each have to distinguish two kinds of flow.
Making the main flow a coroutine removes the special case everywhere. The queues hold one type,
the switch path is single, and the per-coroutine machinery, meaning the internal context and the
awaiting-info descriptions, applies to the main flow like to any other coroutine.

**The main coroutine is replaced, not recycled.** When the script's last statement executes, its
main coroutine has finished, and the code that runs afterward, such as shutdown functions and
destructors, is a different flow. The end-of-main handover makes that explicit: the engine marks
the old main finished, the scheduler drains the remaining coroutines and returns a fresh main
coroutine, which the engine records as the new main and the current one. Returning the finished
main is an `Error`. On normal completion the request performs this handover at the end of the
script and again after destructors have run, so at every point of the request the running flow is
a live coroutine, never a finished one.

### Exceptions and value transfer

A switch is both a send and a receive. One call does two things: it hands control away together
with a value, and, when some later flow switches back, it returns the value that flow passed. The
value is therefore delivered as the return value of the switch call the target is currently
suspended in. This is the symmetric analogue of the `Fiber::resume($v)` and "`$v` comes back from
`Fiber::suspend()`" pair, with no intermediary.

The error parameter uses the same channel: instead of returning the value from the target's
pending switch, it throws the error from it. This is the primitive behind
the enqueue contract, where a non-null error is raised at the coroutine's suspension point: the
scheduler delivers cancellation and I/O failures by switching into the coroutine with the error
instead of a value. Passing both a value and an error is a `ValueError`, since there is nowhere
the value could arrive.

The boundary cases complete the contract:

- **First entry.** A coroutine that has not started has no pending switch to deliver into, so the
  value of a first entry is ignored; the body takes no resume value. A first entry carrying an
  error does not start the body at all: the coroutine finishes with that exception, which then
  surfaces at the switch site as below. This is precisely the cancellation of a coroutine that
  never ran.
- **Body completion.** When the body returns, the result is delivered through the same channel: it
  becomes the return value of the switch call of the flow that last switched into the coroutine. An
  exception the body does not catch travels the same way, as a throw, surfacing at the switch site
  inside the flow that performed the last switch. In practice that flow is the scheduler, so a
  scheduler wraps its switches and records what escapes as the coroutine's unhandled exception. A
  bailout, meaning a fatal error, is not an exception and propagates as a bailout across the
  switch.
- **Finished coroutine.** Switching into a coroutine whose body has completed is an `Error`, as is
  switching into an object the engine does not know as a coroutine, or into a coroutine that never
  received a body.

Exceptions escaping a notification follow from where it was raised. When userland frames sit
beneath the notification, for instance a suspend reached from application code inside a suspending
`sleep()`, the exception surfaces at that suspension point, in the flow that yielded. That is the
natural reading of "the operation failed". When no userland frame exists, for instance at the
end-of-main handover or in an enqueue fired by a reactor callback, there is nobody to deliver to:
the exception is treated as unhandled, reported, and the request is torn down. A scheduler should
therefore not let exceptions escape.

### A minimal scheduler

The handler sketches above add up to a deliberately naive scheduler: FIFO order, no reactor, no
cancellation policy, yet structurally complete, in that every notification is handled and nothing
else is needed to run PHP concurrently. For a complete, runnable implementation this RFC points at
the bridge extension, [ext-scheduler-hook](https://github.com/true-async/ext-scheduler-hook),
whose test suite registers schedulers written in plain PHP and exercises every notification and
granted operation.

Three things are worth noticing in such an implementation. The engine never sees a queue, an
ordering policy or a coroutine class: it raises the notifications and records what the suspend
handler returns. The user-facing API, such as `spawn()`, is ordinary code the scheduler adds on
top. And replacing the naive FIFO loop with the reactor version from the previous section turns it
into a real event-driven scheduler without changing any signature.

One control-flow rule deserves emphasis, because omitting it is the classic bug of a distributed
loop. Control returning from a switch has two distinct meanings: either this flow was itself
dequeued, so its turn came and it should stop scheduling and resume its own work, or the coroutine
it switched into finished or parked, so it should keep draining. The `$handoff` marker in the
reactor loop above tells the two apart. Without it, a flow frozen in the middle of its own
scheduling pass is skipped over and lost, holding a live stack that nothing will ever resume. The
reference C scheduler hit this bug; the loop above is the corrected shape it converged on.

### The coroutine context

A coroutine needs memory of its own. Everything built on top of the scheduler depends on it:
frameworks keep the request id, DI scopes and transaction state per flow, and many PHP functions
keep state that used to be safely global and becomes per-coroutine the moment flows interleave.
And it is a hot path: output buffering resolves its handler stack on every write, far more often
than any context switch occurs. The context is therefore not scheduling policy to route through
the scheduler, but engine machinery. It lives directly in
the engine's coroutine structure and is accessed at C speed, with no scheduler involvement.

The engine owns two such stores per coroutine.

The **internal store** is reserved for the engine and for C extensions. Its keys are
process-unique numeric ids, allocated once per process from a static C string name. C code reads
and writes values through three operations, find, set and unset, taking either the current or an
explicit coroutine, and the store dies with the coroutine. The internal store is structurally
inaccessible from PHP, deliberately: its values are raw C data, frequently bare pointers,
and if they lived in PHP-visible storage, ordinary PHP code could overwrite a pointer or unset an
entry whose memory C code still owns. The boundary is enforced by construction rather than by
convention.

The **userland store** holds ordinary PHP values keyed by strings or objects: a request id, a
tracing span, a locale. The engine owns the storage and the operations, exported for a provider to
wrap. Whether it is exposed to PHP at all, and under what name, is the scheduler provider's
choice; this RFC standardizes the storage, not a class. Each store is created lazily and dies
with its coroutine. A fresh coroutine starts empty; whether a child sees the spawner's values, as
a copy, as a link, or not at all, is inheritance policy and stays in the scheduler's user-facing
API alongside `spawn()`.

### The internal context in practice

Some functions need to keep state tied to a coroutine. An example is `ob_start()`: it pushes a
handler onto a stack, and with thousands of coroutines interleaving in one process, a single
process-global stack would mix their output. Moving the handler stack into the coroutine's
internal store fixes that without changing a line of userland code, and the same four-step pattern
applies to any core subsystem or extension with process-global state to make coroutine-safe:
allocate a key, create the state on first use, dispose of it on the coroutine's finish event, and
resolve it through the current coroutine.

[scheduler_rfc_examples.md](https://github.com/true-async/php-async-core-rfc/blob/main/scheduler_rfc_examples.md)
collects the worked examples with the actual code: output buffering, and `gethostbyname()`, whose
traditional static result buffer becomes per-coroutine state the same way.

*Status: the store and its operations are implemented in the proof of concept. Converting the
affected core subsystems, `ob_start()` among them, is follow-up work and is not part of this
proposal.*

### Microtasks

A microtask extends the scheduler's own behavior: a callable that runs inside the tick, between
coroutine switches. It reaches the scheduler through the defer notification. It runs in the
scheduler's own context and never suspends; it runs to
completion right where the scheduler stands. That makes it far cheaper than a coroutine, and the
right tool when logic must execute at scheduling points but does not itself wait: bookkeeping,
waking sleepers, and incremental algorithms sliced across ticks.

One example is a concurrent iterator. A worker coroutine drives the loop, and a microtask watchdog
spawns a replacement worker whenever the current one suspends, so exactly one coroutine drives the
loop at a time. The destructor phase of garbage collection uses the same pattern while a
scheduler is active: a worker coroutine runs the destructors, and a microtask watchdog replaces a
worker that suspends. See
[scheduler_rfc_examples.md](https://github.com/true-async/php-async-core-rfc/blob/main/scheduler_rfc_examples.md)
for the worked-out code for both.

### Scheduler activity across the request lifecycle

Once registered, the scheduler is **always active**.

- **Launch.** A scheduler registered from C launches immediately before the script code runs; one
  registered during the script launches at its registration point.
- **End of main.** When the main script ends, the engine hands control to the scheduler with the
  end-of-main handover. After a normal completion the scheduler drains the remaining coroutines
  and returns a fresh main coroutine. After an abnormal completion the same handover carries the
  bailout flag, and the scheduler decides whether to finish or discard the remaining work; this is
  its last opportunity to release resources before the request is torn down.
- **After destructors.** Once object destructors have run, the scheduler receives one final
  end-of-main handover, since destructors may have spawned coroutines. After it returns,
  concurrency is terminated and the rest of the request shutdown is synchronous.
- **exit().** A coroutine ending through `exit()` raises the shutdown notification, so concurrency
  ends on the scheduler's terms rather than tearing the request down mid-flight.

Consequently, a script that spawns background work and reaches its final statement does not
silently discard that work. The scheduler defines the semantics of the end of the request.

### Process forking

`fork()` and a live scheduler do not mix. Coroutines parked on a reactor, watcher descriptors and
worker threads cannot survive a fork of the process, so forking with a live scheduler produces a
child in an incoherent state.

This RFC therefore proposes that forking be restricted while a scheduler is registered:
`pcntl_fork()` throws, as does any other extension path that forks the request process. The
default answer is no, and it is the engine that gives it, rather than each scheduler being trusted
to guard itself.

The escape hatch is at the C level, not in PHP. An extension that knows how to survive a fork,
typically the scheduler together with its reactor, registers a pair of handlers: one that runs in
the parent and decides whether this particular fork is permissible, and one that reinitializes
state in the freshly forked child. With no such pair registered, forking is refused. The exact C
interface is documented in
[SCHEDULER.md](https://github.com/true-async/php-async-core-rfc/blob/main/SCHEDULER.md).

*Status: proposed, not yet implemented in the proof of concept.*

## Backward Incompatible Changes

With no scheduler registered there are no behavior changes of any kind, and the engine adds no
names to any namespace.

While a scheduler is active, three behaviors change. All three follow from the engine having more
than one flow, and all three are observable from PHP.

### 1. `Fiber::suspend()` inside a destructor throws

Under an active scheduler, the destructor phase of garbage collection runs in a dedicated
coroutine rather than in whichever flow triggered collection. That coroutine is not a fiber, so
`Fiber::suspend()` called from a destructor running in it throws
`FiberError: Cannot suspend outside of a fiber`.

This breaks existing, tested behavior. The upstream test
[`Zend/tests/fibers/destructors_001.phpt`](https://github.com/php/php-src/blob/master/Zend/tests/fibers/destructors_001.phpt)
does exactly this: it calls `gc_collect_cycles()` inside a fiber and suspends from a destructor.
Verified against the proof of concept: the test passes with no scheduler registered and fails
under one, with the error above.

The trade-off is deliberate. A destructor that can park an arbitrary flow is what makes the
destructor phase unsafe once flows interleave, because the flow it parks is whichever one happened
to allocate past a threshold. Running destructors in a known coroutine makes the phase
predictable, at the cost of this pattern.

### 2. `gc_collect_cycles()` gains new reasons to return `0`

The return value keeps its type and its meaning, the number of collected cycles, and returning `0`
from a reentrant call is existing behavior, unchanged. Under an active scheduler two new paths
return `0`:

- the collection ran on a dedicated coroutine and the calling coroutine was canceled while
  waiting for it;
- a coroutine for the collection could not be created.

In both cases no collection result is available to report. Code that treats `0` as "there was
nothing to collect" will read these as the same thing.

### 3. Forking the process is restricted while a scheduler is active

See "Process forking". `pcntl_fork()` throws while a scheduler is registered, unless the scheduler
has registered fork handlers that permit the specific case.

*Status: proposed, not yet implemented in the proof of concept.*

## Proposed PHP Version(s)

PHP 8.7+.

## RFC Impact

**To SAPIs.** None observable. CLI, FPM, phpdbg and embed gain the lifecycle points described
above, all inert with no scheduler registered.

**To Existing Extensions.** None by default. Extensions that hold per-request state in process
globals continue to work unchanged in the single-flow case. Extensions that wish to become
concurrency-safe can move that state into the per-coroutine internal store, which is opt-in and
mechanical. Extensions that fork the request process are affected by the change above.

**To the Ecosystem.** No new names, so no impact on IDEs, language servers, static analyzers,
auto-formatters or linters. There is no new syntax and no new symbol to recognize.

**To Opcache.** None. No new opcodes and no change to compilation.

**To the JIT.** None. No new opcodes and no change to compiled code.

**To Fibers.** Existing fiber code keeps working. A scheduler may adopt a foreign fiber onto its
schedule, or decline, per fiber. The one behavior change is item 1 above.

**To the Garbage Collector.** The destructor phase runs in a dedicated coroutine while a scheduler
is active. Collection itself is unchanged.

**New Constants.** None.

**php.ini Defaults.** None.

**To Debuggers and Profilers.** A coroutine's execution context is built on the same machinery
`Fiber` already uses, so step debugging and stack traces continue to work.

## Notes for Extension Maintainers

If an extension does not fork the process and does not suspend fibers from destructors, it works
unchanged.

Two things are worth knowing beyond that.

**Making an extension concurrency-safe.** Any extension holding per-request state in a process
global will mix state between flows once concurrency is active. The fix is the four-step pattern
from "The internal context in practice": allocate a key once per process, create the state on
first use in a coroutine, dispose of it when that coroutine finishes, and resolve it through the
current coroutine.

**Implementing a scheduler.** The C-level interface, meaning the slot structure a scheduler fills
in, the exact signatures of the notifications and granted operations, and the fork handler pair,
is documented in
[SCHEDULER.md](https://github.com/true-async/php-async-core-rfc/blob/main/SCHEDULER.md), with a
file-by-file account of every place the
integration touches php-src in
[core-integration.md](https://github.com/true-async/php-async-core-rfc/blob/main/core-integration.md).

## Future Scope

This core is deliberately minimal. It is the foundation for follow-up work, none of which is
proposed here:

- **Asynchronous I/O.** A non-blocking I/O layer for sockets, files, DNS and timers, so that the
  engine's blocking functions yield when a scheduler is active.
- **Concurrency-safe core subsystems.** Converting `ob_start()`, `gethostbyname()` and their
  neighbors to per-coroutine state.
- **Threads.** A parallelism model that cooperates with the scheduler.
- **Connection pooling.** Coroutine-aware pooling, for PDO among others.

## Voting Choices

Requires a 2/3 majority.

> Implement Concurrency Support in the PHP Engine as outlined in the RFC?

Yes / No / Abstain

## Patches and Tests

- **Proof of concept:** https://github.com/true-async/php-src/tree/async-core
  The engine capabilities, the lifecycle points, the per-coroutine stores, and the changes to the
  request lifecycle, the garbage collector and the fiber machinery.
- **Pull request:** https://github.com/php/php-src/pull/22561
- **Reference C scheduler:**
  [ext/test_scheduler](https://github.com/true-async/php-src/tree/async-core/ext/test_scheduler),
  an in-tree extension filling every slot from outside the engine,
  disabled by default so that the upstream test suite runs unchanged in the same binary. It is the
  runtime proof that the capabilities are implementable by an extension, and it carries its own
  test suite exercising every notification, the switch contract and the per-coroutine stores.
- **Reference production scheduler:** https://github.com/true-async/true-async
- **Scheduler bridge for PHP:** https://github.com/true-async/ext-scheduler-hook, the separate
  extension mentioned in "Scope", not part of this proposal.

## Implementation

To be filled in after acceptance: merged version, commit links, manual entries.

## References

- [Polling API RFC](https://wiki.php.net/rfc/poll_api): readiness multiplexing accepted for PHP
  8.6, the natural I/O source for a scheduler.
- [Fibers RFC](https://wiki.php.net/rfc/fibers): the low-level primitive this builds on.
- [True Async RFC](https://wiki.php.net/rfc/true_async): a complete concurrency model built on
  this core.
- [SCHEDULER.md](https://github.com/true-async/php-async-core-rfc/blob/main/SCHEDULER.md): the
  C-level interface, for implementers.
- [core-integration.md](https://github.com/true-async/php-async-core-rfc/blob/main/core-integration.md):
  every place the integration touches php-src, file by file.
- [scheduler_rfc_examples.md](https://github.com/true-async/php-async-core-rfc/blob/main/scheduler_rfc_examples.md):
  worked examples of per-coroutine state and the deferred-task-driven concurrent iterator.
- [reactor.md](https://github.com/true-async/php-async-core-rfc/blob/main/reactor.md): the
  reactor's C-level interface.

## Rejected Features

None.

## Changelog

- **0.9**: initial draft. Supersedes the earlier "Async Scheduler Hook API" draft, which proposed
  a PHP-level registration API; the subject is now the engine capabilities alone, with no PHP
  surface.
