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

This RFC does not add a single class, function, constant or keyword to PHP. It changes what the
engine is able to do.

Today the PHP engine can execute exactly one flow of execution. Fibers (PHP 8.1) gave userland a
way to save and restore a call stack, but the engine itself still assumes one flow throughout:
the top-level script is not a schedulable unit, the garbage collector and the destructor phase
run wherever they happen to be triggered, and the state of built-in functions is global to the
process. Anything that wants to interleave several flows has to work around the engine rather
than with it.

The consequence is visible in the ecosystem. Every concurrency stack (ReactPHP, Revolt, AMPHP,
Swoole) carries its own event loop, its own coroutine abstraction and its own conventions. They
cannot cooperate, because there is nothing in the engine for them to cooperate through, and none
of them can make the standard library non-blocking, because the standard library has no notion of
a flow to suspend.

This proposal fills exactly that gap and nothing more. The engine gains the ability to run more
than one flow of execution and to hand the decision "which flow runs next" to a component outside
itself. That component, the scheduler, is not part of the engine and is not defined here. No
scheduler ships in core, no event loop ships in core, no user-facing API ships in core.

With no scheduler registered, PHP behaves exactly as it does today.

## What Is a Scheduler?

A scheduler is the component that decides which flow of execution runs next. Nothing more.

To see the shape of it, ignore the engine for a moment and write the idea in plain PHP. A flow of
execution that can be parked and resumed is a *coroutine*. A scheduler holds a queue of coroutines
that are ready to run, and it does two things:

```php
// Illustration only. This is not proposed API; it is the idea in twenty lines.

// Make a coroutine runnable.
function enqueue(object $coroutine): void
{
    $this->ready->enqueue($coroutine);
}

// The running flow yields. Choose who runs next and switch into it.
function suspend(): void
{
    $next = $this->ready->dequeue();
    switchTo($next);
}
```

Everything a user would recognise is built on top of these two operations. Spawning a task is
"create a coroutine and enqueue it":

```php
function spawn(callable $task): object
{
    $coroutine = newCoroutine($task);
    enqueue($coroutine);
    return $coroutine;
}
```

A non-blocking `sleep()` is "arm a timer, park, let the timer wake you":

```php
function sleep(float $seconds): void
{
    $coroutine = currentCoroutine();
    addTimer($seconds, fn () => enqueue($coroutine));  // fires later
    suspend();                                          // others run meanwhile
}
```

That is the entire concept. `await()`, channels, futures, connection pools and everything else in
a concurrency library are elaborations of these few lines.

Two of the operations above cannot be written in PHP at all. `newCoroutine()` has to allocate a
real execution context, and `switchTo()` has to swap the machine stack. Those are engine
operations, and they are the subject of this RFC. The queue, the ordering policy, the timer, the
notion of a "task" and every name a user ever types are not.

## Current Situation in PHP

PHP already has the low-level half of this. `Fiber` allocates an execution context and switches
into it. What is missing is everything that would let the engine participate:

- **The top-level script is not a flow the engine can park.** It is the one flow that simply runs.
  A scheduler cannot treat it like any other unit of work, so every scheduler carries a special
  case for "the main flow" throughout its code.
- **The engine has no notion of a current flow.** No engine subsystem can ask "who is running now",
  so no engine subsystem can keep state per flow.
- **Built-in state is process-global.** `ob_start()` pushes onto one global handler stack;
  `gethostbyname()` writes into one static buffer. The moment two flows interleave, they corrupt
  each other. This is why no userland scheduler can make the standard library non-blocking: even
  if it could suspend inside `file_get_contents()`, the surrounding state is shared.
- **The garbage collector and the destructor phase assume a single flow.** A destructor that
  parks would park whatever flow happened to trigger collection.
- **Fibers created by different libraries are invisible to each other.** If two libraries in one
  process each drive their own fibers, neither can know about the other's.

None of these are policy questions. They are structural properties of the engine, and no amount of
userland code can change them.

## Primary Motivation: Engine Capability

The proposal is that the engine becomes able to execute more than one flow, and delegates the
choice of which flow runs to an external component.

Concretely, the engine gains five capabilities. Each is stated as a property of the engine, not as
an API:

1. **The top-level script is a coroutine.** From the first opcode, the script runs as a
   schedulable unit like any other, so the scheduler holds one kind of thing in its queues rather
   than "coroutines, plus the special one".
2. **The engine knows which coroutine is running.** It never chooses one; it records what the
   scheduler tells it. Every subsystem can then ask "who is running now".
3. **The engine can keep state per coroutine.** A storage area attached to each coroutine,
   accessible at C speed, is what lets `ob_start()`, `gethostbyname()` and any extension with
   process-global state become concurrency-safe without changing a line of userland code.
4. **The engine can switch symmetrically between coroutines.** Fibers are asymmetric: a fiber can
   only yield back to whoever resumed it, so going from A to B costs two switches through an
   intermediary. A symmetric switch goes from A to B directly.
5. **The engine notifies the scheduler at the points where a decision is needed.** Startup, a
   voluntary yield, the end of the script, the end of the destructor phase, the creation of a
   foreign fiber.

The scheduler supplies the answers. It is ordinary extension code, loaded or not, and its absence
is the default.

**This is the whole proposal.** The sections that follow describe these five capabilities in more
detail, show one possible implementation as an illustration, and state the compatibility
consequences.

### Why this is worth a vote even though it adds no userland API

Under the current
[release process policy](https://github.com/php/policies/blob/main/release-process.rst), internal
API changes that do not affect the user-facing API do not strictly require an RFC. This proposal
is brought to a vote anyway, for two reasons.

First, it does contain user-visible behaviour changes when a scheduler is active (see "Backward
Incompatible Changes"), and those must go through the RFC process.

Second, and more importantly, a change of this size should carry an explicit mandate. It touches
the request lifecycle, the garbage collector and the fiber machinery. Landing that quietly and
discovering the objections afterwards would serve nobody.

There is precedent in both directions. Internals-only hooks have landed without any RFC: the
observer API, which every APM profiler in the ecosystem depends on, was merged directly as
[php-src PR #5857](https://github.com/php/php-src/pull/5857) after the PHP 8.0 feature freeze,
with no vote. Internals-only changes have also been put to a vote and passed decisively:

| RFC | Version | Vote | Userland surface |
|---|---|---|---|
| [Turn gc_collect_cycles into function pointer](https://wiki.php.net/rfc/gc_fn_pointer) | 7.0 | 18 / 0 | none |
| [Fast Parameter Parsing API](https://wiki.php.net/rfc/fast_zpp) | 7.0 | 19 / 1 | none |
| [Native TLS](https://wiki.php.net/rfc/native-tls) | 7.0 | 28 / 0 | none |
| [Move phpng to master](https://wiki.php.net/rfc/phpng) | 7.0 | 47 / 2 | none |
| [New JIT based on IR Framework](https://wiki.php.net/rfc/jit-ir) | 8.4 | 26 / 0 | none |
| [Polling API](https://wiki.php.net/rfc/poll_api) | 8.6 | 33 / 1 | secondary |

The closest analogue is `gc_fn_pointer`: a hook added to the engine purely so that external
tooling could do something it otherwise could not.

## Secondary Benefit: What Extensions Can Build

Nothing in this section is proposed for core. It exists to answer the fair question "what is this
for", and every name below belongs to an extension, not to PHP.

**A scheduler extension.** The reference implementation is
[TrueAsync](https://github.com/true-async/true-async), a C extension providing coroutines, an
event loop over libuv, channels, futures, cancellation and a thread pool. It is one design among
many. A different extension could implement work-stealing, structured concurrency, an actor model
or a deterministic test scheduler, and this RFC neither blesses nor blocks any of them.

**Non-blocking standard library.** Once the engine can suspend a flow and keep per-flow state, a
scheduler extension can make the blocking functions yield instead of blocking: file and socket
I/O, DNS, `sleep()`. Existing sequential code then runs concurrently with light adaptation rather
than a rewrite, with no function colouring and no parallel set of async APIs to learn. TrueAsync
demonstrates this today.

**Cooperation between existing libraries.** ReactPHP, Revolt, AMPHP and Swoole currently each
drive their own fibers with no knowledge of each other. The fiber notification described in the
proposal lets a scheduler adopt a foreign fiber onto its own schedule, so libraries built on
fibers can share one runtime instead of fighting for it.

**Reusing the Polling API.** The [Polling API](https://wiki.php.net/rfc/poll_api) accepted for PHP
8.6 gives the engine readiness multiplexing over epoll/kqueue/IOCP. It is the natural I/O source
for a scheduler, and it currently has no in-core consumer that drives a concurrent runtime. The
two halves fit together: polling answers "which descriptor is ready", scheduling answers "which
flow runs next".

**An illustrative PHP-level implementation.** During development, the hook set was also exposed to
PHP so that a scheduler could be written in plain PHP and driven by ordinary tests. That bridge
lives in a separate extension and is **not part of this proposal**; it is referenced only as
evidence that the capabilities are sufficient and implementable from outside the engine. Whether
such a bridge exists, and under what names, is a decision for whoever ships it.

## Why Not Ship a Scheduler in Core?

The obvious alternative is to put a scheduler in the engine and be done with it. This proposal
deliberately does not, for four reasons.

**There is no consensus on the model.** Structured concurrency, unstructured spawning, actors,
explicit `async`/`await`, colourless coroutines: these are live design disagreements, and the
ecosystem currently holds several of them simultaneously. Freezing one into the engine would
settle by fiat a question that has not been settled by argument.

**A scheduler is policy, and policy changes.** Ordering, fairness, cancellation semantics, priority
and backpressure are exactly the parts that get revised as real workloads arrive. Extensions can
revise them; the engine cannot, because whatever it ships becomes a compatibility contract on the
next release.

**The existing ecosystem should not be invalidated.** ReactPHP, Revolt, AMPHP and Swoole represent
years of accumulated work. A core scheduler would make all of them wrong. A seam makes all of them
possible.

**The smaller change is the reversible one.** If the capabilities proposed here turn out to be the
wrong shape, they are internal and can be changed. A user-facing concurrency model cannot be.

This is the same reasoning the Polling API applied when it declined to bundle libuv or libevent:
provide the mechanism, leave the policy outside.

## Proposal

The engine gains a set of decision points, each of which asks the scheduler a question, plus three
operations the scheduler is allowed to perform. Everything is described here as logic. No C
signatures appear in this section; extension authors will find them in "Notes for Extension
Maintainers".

### Decision points

The engine notifies the scheduler at six points. In each case the engine performs no scheduling of
its own: it asks, then does what it is told.

**Launch.** The scheduler starts, and returns the coroutine the top-level script will run in. The
main flow is a coroutine from its first opcode rather than a plain flow that becomes one later, so
there is never a moment where the queues contain two kinds of thing.

**Suspend.** The running flow yields. The scheduler picks who runs next, switches into it, and
eventually returns the coroutine that is running when control comes back. This return value is the
only way the engine ever learns which coroutine is current.

Two flags qualify the call:

- *end of main*: the script's code is finished, and whatever runs afterwards (shutdown functions,
  destructors) is a different flow. The scheduler drains the remaining work and returns a fresh
  main coroutine. A request performs this twice: at the end of the script and again after
  destructors have run.
- *bailout*: the main flow ended abnormally (a fatal error). The scheduler may discard remaining
  work rather than complete it. This is its last chance to release resources.

**Enqueue.** Make a coroutine runnable. Creating a new coroutine and resuming a parked one are the
same operation. An error may be attached, in which case it is raised at the coroutine's suspension
point: this is how cancellation, timeouts and I/O failures reach waiting code. The scheduler may
decline (during shutdown, for example), which is a normal state and not a failure.

**Foreign fiber.** A `Fiber` created by application or third-party code is starting. The scheduler
may adopt it onto its schedule, or leave it as a plain fiber. This exists so that libraries which
already drive their own fibers keep working: if the engine adopted every fiber automatically, such
a library would recurse into itself. The engine tracks nothing here, so no outside code can mark a
fiber as internal.

**Defer.** Queue a one-shot task to run at the next scheduling point. The engine stores nothing;
the queue belongs to the scheduler. This is what lets logic run between switches without being a
coroutine itself.

**Shutdown.** A graceful shutdown has been requested. The scheduler stops accepting work and
decides the fate of what remains: run it to completion, or cancel it. Unlike the other five, this
one is not tied to a fixed point in the request lifecycle: it is raised by whoever decides that
concurrency must end early, which in the reference stack is `exit()` called inside a coroutine.
*Status: raised by the engine in the full reference tree when a coroutine ends through `exit()`;
that path is not yet ported into the reduced proof of concept linked below.*

### Operations granted to the scheduler

Three operations are handed to the scheduler when it starts, and to nobody else:

- **Bind a body to a coroutine.** The scheduler creates its own coroutine objects; this gives one
  of them something to execute.
- **Switch into a coroutine.** The symmetric switch. A value passed in arrives as the result of
  the switch the target is parked in; an error passed instead is thrown from that point. The main
  coroutine, an adopted fiber and the scheduler's own coroutines all switch through this one path.
- **Read the current coroutine.** Returns what the engine recorded.

These are granted, not published. They are not functions in any function table and there is no
public counterpart to call, so holding them is a privilege of being the registered scheduler
rather than an API available to any code.

### Value and error transfer

A switch is both a send and a receive: it hands control away with a value, and returns the value
that some later flow passes back. This is the symmetric analogue of `Fiber::resume($v)` arriving
as the result of `Fiber::suspend()`, without the intermediary.

The boundaries complete the contract:

- **First entry.** A coroutine that has not started has no pending switch to deliver into, so an
  entry value is ignored. An entry carrying an error does not start the body at all: the coroutine
  finishes with that error. This is exactly the cancellation of a coroutine that never ran.
- **Completion.** When a body returns, the result arrives as the return value of whoever switched
  into it last. An uncaught exception travels the same path as a throw, surfacing at the switch
  site rather than at the coroutine. In practice that site is the scheduler, so a scheduler wraps
  its switches and records what escapes. A fatal error is not an exception and propagates as a
  bailout.
- **Finished coroutine.** Switching into a coroutine whose body has completed is an error, as is
  switching into an object the engine does not recognise as a coroutine.

### Registration

A scheduler is registered once per process, and from that moment concurrency is active: there is
no lazy initialisation and no implicit start on the first asynchronous call. Registering a second
one is an error. With none registered, every decision point above is inert.

## The Coroutine Context

A coroutine needs memory of its own, and this is the part of the proposal with the widest reach,
because it is what makes the existing standard library concurrency-safe.

Consider `ob_start()`. It pushes a handler onto a stack that is global to the process. With one
flow of execution that is correct. With several interleaving flows, output from different flows
mixes into the wrong buffers. The same is true of `gethostbyname()`, whose result buffer is a
single static, and of every extension holding per-request state in a global.

The fix is to attach the state to the coroutine rather than to the process. The engine therefore
gives each coroutine a storage area, and the affected subsystems resolve their state through the
running coroutine instead of through a global. Userland code does not change: `ob_start()` keeps
its signature and its behaviour, and simply becomes correct under concurrency.

This is deliberately engine machinery rather than a scheduling decision routed through the
scheduler. Output buffering resolves its handler stack on every byte written, orders of magnitude
more often than any context switch happens, so the lookup has to be a direct field access rather
than a call out to an extension.

There are two such stores.

The **internal store** is for the engine and for C extensions. Its keys are process-unique numeric
ids, its values are raw C data, and it is structurally unreachable from PHP. That inaccessibility
is the point: the values are frequently bare pointers whose memory C code owns, and PHP code able
to overwrite or unset them could corrupt engine state. The boundary is enforced by construction
rather than by convention.

The **userland store** holds ordinary PHP values keyed by strings or objects: a request id, a
tracing span, a locale. The engine owns the storage and the operations. Whether it is exposed to
PHP at all, and under what name, is left to whoever ships a scheduler; this RFC standardises the
storage, not a class.

Both stores are created lazily and destroyed with their coroutine. A fresh coroutine starts empty;
whether a child sees anything of its parent is inheritance policy and belongs to the scheduler.

## Examples

All code in this section is illustration. None of these names are proposed for core.

### A minimal scheduler

Deliberately naive: FIFO order, no event loop, no cancellation policy. It is nonetheless
structurally complete, and demonstrates that the decision points are sufficient.

```php
// Illustration. MyCoroutine is the scheduler's own class; the engine never
// sees its shape and only passes it back and forth.

final class MiniScheduler
{
    private SplQueue $ready;        // [coroutine, ?error] pairs
    private SplQueue $microtasks;   // deferred one-shot tasks
    private ?object $main = null;
    private ?object $handoff = null;

    // The three granted operations arrive here and go no further.
    public function __construct(
        private readonly Closure $bindEntry,
        private readonly Closure $switchTo,
        private readonly Closure $currentCoroutine,
    ) {
        $this->ready = new SplQueue();
        $this->microtasks = new SplQueue();
    }

    // Launch: the main flow is a coroutine from the first opcode.
    public function onLaunch(): object
    {
        return $this->main = new MyCoroutine();
    }

    // spawn() is the scheduler's own API, not part of this RFC.
    public function spawn(Closure $task): object
    {
        $coroutine = new MyCoroutine();
        ($this->bindEntry)($coroutine, $task);
        $this->onEnqueue($coroutine);
        return $coroutine;
    }

    public function onEnqueue(object $coroutine, ?Throwable $error = null): bool
    {
        $this->ready->enqueue([$coroutine, $error]);
        return true;
    }

    public function onSuspend(bool $fromMain, bool $isBailout): ?object
    {
        $self = ($this->currentCoroutine)();

        while (true) {
            while (!$this->microtasks->isEmpty()) {
                ($this->microtasks->dequeue())();
            }

            if ($this->ready->isEmpty()) {
                break;                      // a real scheduler blocks in the
            }                               // event loop here instead

            [$current, $error] = $this->ready->dequeue();

            if ($current === $self) {
                if ($fromMain) {
                    continue;               // the finished main cannot run again
                }
                if ($error !== null) {
                    throw $error;           // delivered at the suspension point
                }
                break;                      // its own turn came
            }

            try {
                $this->handoff = $current;
                ($this->switchTo)($current, null, $error);
            } catch (Throwable $unhandled) {
                $current->unhandledException = $unhandled;
            }

            if ($this->handoff === $self) {
                break;                      // dequeued while scheduling
            }
        }

        $this->handoff = null;

        if ($fromMain) {
            return $this->main = new MyCoroutine();   // a fresh main
        }

        return $self;
    }

    public function onFiber(Fiber $fiber): ?object
    {
        return new MyCoroutine();           // adopt every foreign fiber
    }

    public function onDefer(callable $task): void
    {
        $this->microtasks->enqueue($task);
    }

    public function onShutdown(): void
    {
        // Policy: stop accepting work, then cancel or drain the rest.
    }
}
```

Three things are worth noticing. The engine never sees a queue, an ordering policy or a coroutine
class: it fires the decision points and records what the suspend point returns. Everything a user
would touch (`spawn()` here) is ordinary code layered on top. And replacing the naive loop with
one that blocks in an event loop turns this sketch into a real runtime without changing a single
signature.

### Blocking in the event loop

The only structural change a real scheduler makes is what it does when the queue runs dry: rather
than stopping, it sleeps in the kernel until an event arrives.

```php
public function onSuspend(bool $fromMain, bool $isBailout): ?object
{
    $self = ($this->currentCoroutine)();

    while ($this->hasLiveCoroutines()) {
        if ($this->ready->isEmpty()) {
            Poll::run(block: true);   // sleep until a descriptor or timer fires;
            continue;                 // the callback re-queues the woken coroutine
        }

        $current = $this->ready->dequeue();

        if ($current === $self) {
            break;
        }

        $this->handoff = $current;
        ($this->switchTo)($current);

        if ($this->handoff === $self) {
            break;
        }
    }

    return $self;
}
```

Coroutines run until they all park on I/O; the queue empties; the scheduler blocks; an event
fires; a callback re-queues a coroutine; the scheduler switches into it. Nothing is lost and the
thread never spins.

### One rule that is easy to get wrong

Control returning from a switch has two distinct meanings: either this flow was itself scheduled
(its turn came, so it should stop scheduling and resume its own work), or the coroutine it
switched into finished or parked (so it should keep draining). The `$handoff` marker above
distinguishes them.

Omitting this is the classic bug of a distributed loop: a flow frozen in the middle of its own
scheduling pass is skipped over and never resumed, holding a live stack forever. The reference C
scheduler in the proof of concept hit exactly this on its first run; the loops above are the
corrected shape.

## Backward Incompatible Changes

With no scheduler registered there are no behaviour changes of any kind, and no names are added to
any namespace by the engine.

While a scheduler is active, three behaviours change. All three are consequences of the engine
having more than one flow, and all three are observable from PHP.

### 1. `Fiber::suspend()` inside a destructor throws

Under an active scheduler, the destructor phase of garbage collection runs in a dedicated
coroutine rather than in whichever flow triggered collection. That coroutine is not a fiber, so
`Fiber::suspend()` called from a destructor running in it throws
`FiberError: Cannot suspend outside of a fiber`.

This breaks existing, tested behaviour. The upstream test
[`Zend/tests/fibers/destructors_001.phpt`](https://github.com/php/php-src/blob/master/Zend/tests/fibers/destructors_001.phpt)
("Fibers in destructors 001: Suspend in destructor") does exactly this: it calls
`gc_collect_cycles()` inside a fiber and suspends from a destructor. Verified against the proof of
concept: the test passes with no scheduler registered and fails under one, with the error above.

This is the most significant compatibility consequence in the proposal and it is stated first for
that reason. The trade-off: a destructor that can park an arbitrary flow is precisely what makes
the destructor phase unsafe once flows interleave, since the flow it parks is whichever one
happened to allocate past a threshold. Running destructors in a known coroutine makes the phase
predictable, at the cost of this pattern.

### 2. `gc_collect_cycles()` gains new reasons to return `0`

The return value keeps its type and its meaning ("how many cycles were collected"), and returning
`0` from a reentrant call is existing behaviour, unchanged. Under an active scheduler two new
paths return `0`:

- the collection ran on a dedicated coroutine and the calling coroutine was cancelled while
  waiting for it;
- a coroutine for the collection could not be created.

In both cases no collection result is available to report. Code that treats `0` as "there was
nothing to collect" will read these as the same thing.

### 3. Forking the process is restricted while a scheduler is active

Coroutines parked on an event loop, watcher descriptors and worker threads cannot survive a fork
of the process, so forking with a live scheduler produces a child in an incoherent state.

This RFC therefore proposes that forking be restricted while a scheduler is registered:
`pcntl_fork()` throws, as does any other extension path that forks the request process. The
default answer is no, and it is the engine that says it, rather than each scheduler being trusted
to guard itself.

The escape hatch is at the C level, not in PHP: an extension that knows how to survive a fork
(typically the scheduler together with its event loop) registers a pair of handlers, one deciding
in the parent whether this particular fork is permissible and one reinitialising state in the
child. With no such pair registered, forking is refused.

*Status: proposed, not yet implemented in the proof of concept.*

## Proposed PHP Version(s)

Next PHP 8.x.

Realistically this means PHP 8.7. The minimum process time for an RFC today is roughly a month
(14 days of discussion, 2 to 7 days of Intent to Vote, 14 days of voting), which places any
possible acceptance after the PHP 8.6 feature freeze in mid-August 2026.

## RFC Impact

**To SAPIs.** None observable. CLI, FPM, phpdbg and embed gain the decision points described
above, all inert with no scheduler registered.

**To Existing Extensions.** None by default. Extensions that hold per-request state in process
globals continue to work unchanged in the single-flow case. Extensions that wish to become
concurrency-safe can move that state into the per-coroutine internal store; this is opt-in and
mechanical. Extensions that fork the request process are affected by the `pcntl_fork()` change
above.

**To the Ecosystem.** No new names, so no impact on IDEs, language servers, static analysers,
auto-formatters or linters: there is no new syntax and no new symbol to recognise. Event loop
libraries (ReactPHP, Revolt, AMPHP, Swoole) gain a seam through which they can cooperate rather
than each owning the runtime.

**To Opcache.** None. No new opcodes, no change to compilation.

**To the JIT.** None. No new opcodes and no change to compiled code.

**To Fibers.** Existing fiber code keeps working. A scheduler may adopt a foreign fiber onto its
schedule, or decline, per fiber. The one behaviour change is item 1 in "Backward Incompatible
Changes".

**To the Garbage Collector.** The destructor phase runs in a dedicated coroutine while a scheduler
is active. Collection itself is unchanged.

**New Constants.** None.

**php.ini Defaults.** None.

**To Debuggers and Profilers.** A coroutine's execution context is built on the same machinery
`Fiber` already uses, so step debugging and stack traces continue to work. Tools that already
understand fibers will see coroutines as fibers.

## Notes for Extension Maintainers

Extension authors do not need to read the rest of this document to answer the only question that
usually matters: *does my extension still work?* If it does not fork the process and does not
suspend fibers from destructors, the answer is yes, unchanged.

Two things are worth knowing beyond that.

**Making an extension concurrency-safe.** Any extension holding per-request state in a process
global will mix state between flows once concurrency is active. The fix is a four-step pattern:
allocate a key once per process, create the state on first use in a coroutine, dispose of it when
that coroutine finishes, and resolve it through the current coroutine instead of through the
global. The engine provides find, set and unset operations on the per-coroutine internal store for
exactly this.

**Implementing a scheduler.** The C-level interface (the slot structure a scheduler fills in, the
exact signatures of the decision points and the granted operations, the fork handler pair) is
documented separately in [SCHEDULER.md](SCHEDULER.md), with a file-by-file account of every place
the integration touches php-src in
[core-integration.md](https://github.com/true-async/php-async-core-rfc/blob/main/core-integration.md).
Keeping the C signatures out of this document is deliberate: they are the implementation of the
proposal, not the proposal.

## Future Scope

This proposal is deliberately the smallest change that makes concurrency possible. It is the
foundation for follow-up work, none of which is proposed here:

- **Non-blocking standard library.** Making the engine's blocking functions (sockets, files, DNS,
  timers) yield when a scheduler is active.
- **Threads.** A parallelism model that cooperates with the scheduler rather than competing with
  it.
- **Connection pooling.** Coroutine-aware pooling, for PDO among others, reusing connections
  across flows.
- **A user-facing concurrency API.** If the ecosystem converges on one model, standardising it
  becomes a later question. This RFC deliberately does not pre-empt that answer.

## Voting Choices

Requires a 2/3 majority.

> Implement Concurrency Support in the PHP Engine as outlined in the RFC?

Yes / No / Abstain

## Patches and Tests

- **Proof of concept:** https://github.com/true-async/php-src/tree/async-core
  The engine capabilities, the decision points, the per-coroutine stores, and the changes to the
  request lifecycle, the garbage collector and the fiber machinery.
- **Pull request:** https://github.com/php/php-src/pull/22561
- **Reference C scheduler:** an in-tree extension filling every slot from outside the engine,
  gated off by default so that the upstream test suite runs unchanged in the same binary. It is
  the runtime proof that the capabilities are implementable by an extension.
- **Reference production scheduler:** https://github.com/true-async/true-async
- **Tests:** the upstream suite passes unchanged with no scheduler registered. The scheduler
  extension carries its own suite exercising every decision point, the switch contract and the
  per-coroutine stores.

## Implementation

To be filled in after acceptance: merged version, commit links, manual entries.

## References

- [Polling API RFC](https://wiki.php.net/rfc/poll_api): readiness multiplexing accepted for PHP
  8.6, the natural I/O source for a scheduler.
- [Fibers RFC](https://wiki.php.net/rfc/fibers): the low-level primitive this builds on.
- [Turn gc_collect_cycles into function pointer](https://wiki.php.net/rfc/gc_fn_pointer): the
  closest precedent for an internals-only hook.
- [php-src PR #5857](https://github.com/php/php-src/pull/5857): the observer API, an internals-only
  hook that landed without an RFC.
- [core-integration.md](https://github.com/true-async/php-async-core-rfc/blob/main/core-integration.md):
  every place the integration touches php-src, file by file.
- [SCHEDULER.md](SCHEDULER.md): the C-level interface, for implementers.
- [scheduler_rfc_examples.md](https://github.com/true-async/php-async-core-rfc/blob/main/scheduler_rfc_examples.md):
  worked examples of per-coroutine state (`ob_start()`, `gethostbyname()`).
- [TrueAsync project](https://github.com/true-async): the full stack from which these capabilities
  were extracted.

## Rejected Features

**Shipping a scheduler in core.** See "Why Not Ship a Scheduler in Core?".

**Standardising a PHP-level API for registering a scheduler.** An earlier draft of this proposal
defined classes and an interface in an `Async\` namespace through which a scheduler could be
written in PHP. That surface has been removed: it is one possible implementation, useful for
testing and experimentation, and it belongs to whoever ships it rather than to the engine. The
engine compiles in no PHP symbols.

**Exposing the execution context as an object.** An earlier draft exposed the switch primitive as
a PHP class. Any holder of such an object holds the ability to switch execution, which is a
privilege that should belong to the scheduler alone. It is now a granted operation instead.

## Changelog

- **0.9**: initial draft. Supersedes the earlier "Async Scheduler Hook API" draft, which proposed
  a PHP-level registration API; the subject is now the engine capabilities alone, with no PHP
  surface.
