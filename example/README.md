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

## The example

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
