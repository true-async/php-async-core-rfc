# Pure-PHP scheduler example

A minimal, self-contained scheduler written entirely in PHP on top of the
**Async Scheduler Hook API**, plus an example that uses it. It shows that once
a scheduler is registered, ordinary `Fiber`s become cooperatively-scheduled
coroutines that really switch.

Needs a PHP built with the async core (the
[`async-core`](https://github.com/php/php-src/pull/22561) branch).

## The module

[`CooperativeScheduler.php`](CooperativeScheduler.php) is the whole driver and
nothing else. Including it registers the scheduler; it exposes two functions,
the pure-PHP twins of ext/async's `Async\spawn()` / `Async\suspend()`:

```php
Cooperative\spawn(callable $task, mixed ...$args);  // start a coroutine
Cooperative\suspend();                              // cooperative yield
```

Internally it is four hooks over two queues (closures capturing the queues via
`use`):

| Hook | Policy |
|------|--------|
| `INTERCEPT_FIBER` | wrap a starting fiber into a coroutine handle |
| `ENQUEUE` / `RESUME` | put a runnable coroutine at the tail of the queue |
| `DEFER` | store a one-shot microtask (used e.g. by a concurrent iterator) |
| `SUSPEND` | the current flow yields — run the queued coroutines |

The context switch itself is never hand-rolled: inside the scheduler it is just
`$fiber->start()` / `$fiber->resume()`, which the engine turns into a direct
switch. `SUSPEND` also receives `bool $isBailout`, so the scheduler can choose
to complete or drop the pending coroutines when the main flow ends abnormally.

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
