# Pure-PHP scheduler example

A minimal, self-contained scheduler written entirely in PHP on top of the
**Async Scheduler Hook API**, plus an example that uses it. It shows that once
a scheduler is registered, ordinary `Fiber`s become cooperatively-scheduled
coroutines that really switch.

Needs a PHP built with the async core (the
[`async-core`](https://github.com/php/php-src/pull/22561) branch).

## The module

[`CooperativeScheduler.php`](CooperativeScheduler.php) is the whole driver: a
class that implements `Async\Scheduler` by extending `Async\AbstractScheduler`,
so it overrides only the hooks it needs and keeps its state in ordinary
properties. Register an instance to switch PHP into concurrent mode:

```php
Async\SchedulerHook::register('cooperative', new CooperativeScheduler());
```

The hook methods (scheduling *policy*) map an engine event to a queue operation:

| Hook method | Policy |
|-------------|--------|
| `interceptFiber()` | wrap a starting fiber into a coroutine handle |
| `enqueue()` | put a runnable coroutine at the tail of the queue |
| `suspend()` | the current flow yields — run the queued coroutines |
| `defer()` | store a one-shot microtask (used e.g. by a concurrent iterator) |

The context switch itself is never hand-rolled: inside a hook it is just
`$fiber->start()` / `$fiber->resume()`, which the engine turns into a direct
switch. `suspend()` also receives `bool $isBailout`, so the scheduler can drop
the pending coroutines when the main flow ends abnormally.

The file also defines the user-facing helpers, kept deliberately apart from the
scheduler hooks:

```php
spawn(callable $task, ...$args);  // start a coroutine
park();                           // cooperative yield: reschedule self, then yield
await();                          // suspend until Async\Coroutine::resume() wakes us
```

`park()` is named apart from the `suspend()` hook on purpose: the hook is the
engine contract, `park()`/`await()` are what application code calls.

### Telling the engine which coroutine is current

There is **no setter**. The scheduler returns a coroutine object from
`interceptFiber()`, the engine binds it to the fiber, and from then on the
engine tracks the current coroutine automatically at every switch. Application
code reads and wakes coroutines through `Async\Coroutine`:

```php
Async\Coroutine::current(): ?object;                        // the running coroutine, or null in main
Async\Coroutine::resume(object $coroutine, ?Throwable $e);  // deferred wake — re-queue, never an immediate switch
```

## The examples

### `interleaving.php` — coroutines interleave

[`interleaving.php`](interleaving.php) spawns two coroutines that each do a few
steps and yield after every step. They run **concurrently**, interleaved step
by step:

```
$ php example/interleaving.php
main: spawning two coroutines
main: end of script — handing control to the scheduler
    [A] step 1 of 3
    [B] step 1 of 2
    [A] step 2 of 3
    [B] step 2 of 2
    [A] step 3 of 3
    [B] finished
    [A] finished
```

The alternating `A, B, A, B, …` is the proof that control switches between the
two fibers. Nothing runs while the main script executes; when it ends, the
engine hands control to the scheduler (`SUSPEND` with `fromMain = true`) and the
queued coroutines run to completion — that hand-off is the main flow switching
into the coroutines.

### `await.php` — awaiting a value across coroutines

[`await.php`](await.php) builds a one-slot `Future`: one coroutine records
itself with `Async\Coroutine::current()` and suspends; another resolves the
future and wakes it with `Async\Coroutine::resume()`.

```
$ php example/await.php
main: end of script
consumer: awaiting the value
producer: resolving the future
producer: done
consumer: got 'hello'
```

The wake is *deferred*: `resume()` re-queues the consumer for the scheduler to
run, rather than switching into it from the producer's stack — so no
`FiberError`, unlike a raw `$fiber->resume()` from another fiber.

### `nested.php` — a coroutine spawns a coroutine

[`nested.php`](nested.php) shows that spawning a coroutine from *inside* another
coroutine just works — nesting is not a problem:

```
$ php example/nested.php
main: spawning the parent coroutine
main: end of script
  parent: start
  parent: spawning a child coroutine from inside a coroutine
    child C: step 1
  parent: after the child, another step
    child C: step 2
  parent: done
```

It works because the scheduler never switches fiber-to-fiber directly. Every
coroutine yields back to the central pump (running in the main flow) before the
next one is resumed, so no coroutine is ever resumed while it is still on the
stack.

### `fiber_switch_limit.php` — the rule the pump protects us from

[`fiber_switch_limit.php`](fiber_switch_limit.php) uses plain fibers (no
scheduler) to show the stack discipline that makes a central pump necessary. A
fiber can only suspend to its **immediate** resumer, and a running fiber cannot
be resumed at all:

```
$ php example/fiber_switch_limit.php
A: start B
 B: start C
  C: start D
   D: suspend (hoping to land in main)
  C: >>> control came back to ME (C), not to main <<<
 B: back in B
A: back in A
main: back in main — D is stranded, suspended forever

X: start Y
  Y: try to resume X (an ancestor that is still running)
  Y: FiberError -> Cannot resume a fiber that is not suspended
X: back in X
```

Deep in a `main → A → B → C → D` hierarchy, `D`'s `suspend()` lands on `C`, not
on `main`; and resuming a still-running ancestor throws `FiberError`. A scheduler
that tried to switch coroutine-to-coroutine directly would hit exactly this — the
central pump is what keeps every switch legal.
