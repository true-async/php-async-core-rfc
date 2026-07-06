# Pure-PHP scheduler example

A complete, minimal demonstration of the **Async Scheduler Hook API** written
entirely in PHP, on top of plain `Fiber`. It shows that once a scheduler is
registered, ordinary fibers become cooperatively-scheduled coroutines that
really switch — including a switch driven from the main flow.

Needs a PHP built with the async core (the
[`async-core`](https://github.com/php/php-src/pull/22561) branch).

## The scheduler in one file

[`CooperativeScheduler.php`](CooperativeScheduler.php) is the whole driver: a
run queue plus four hooks. Each hook maps an *engine event* to a *scheduling
policy*:

| Hook | Speaking method | Policy |
|------|-----------------|--------|
| `INTERCEPT_FIBER` | `adoptFiber()`        | wrap a starting fiber into a coroutine handle |
| `ENQUEUE` / `RESUME` | `markReady()`      | put a runnable coroutine at the tail of the queue |
| `SUSPEND` | `runUntilAllIdle()`           | the current flow yields — switch into ready coroutines |
| `DEFER` | `queueMicrotask()`              | store a one-shot microtask (used e.g. by a concurrent iterator) |

The context switch itself is never hand-rolled: inside the scheduler it is just
`$fiber->start()` / `$fiber->resume()`, which the engine turns into a direct
switch.

## Demo 1 — coroutines interleave

[`run.php`](run.php) spawns two coroutines that each do a few steps and yield
after every step. They run **concurrently**, interleaved step by step:

```
$ php example/run.php
main: starting, about to spawn two coroutines
main: both coroutines are queued but not finished yet
main: reaching the end of the script
main: --- handover to scheduler ---
    [microtask] ran on the scheduler tick
    [A] step 1 of 3
    [B] step 1 of 2
    [A] step 2 of 3
    [B] step 2 of 2
    [A] step 3 of 3
    [B] finished
    [A] finished
```

`A, B, A, B, …` alternating is the proof: control is switching between the two
fibers. When the script ends, the engine hands control to the scheduler one
last time (`SUSPEND` with `fromMain = true`) and the queued coroutines run to
completion.

## Demo 2 — switching from the main flow

[`switch_from_main.php`](switch_from_main.php) steps a single coroutine by hand
from the main script, so you can watch control bounce **main → coroutine →
main**, with values passed both ways:

```
$ php example/switch_from_main.php
main: switch INTO the coroutine
    [coroutine] counting 3
main: control is back; coroutine handed me 3

main: switch back into the coroutine
    [coroutine] counting 2
main: control is back; coroutine handed me 2

main: switch back into the coroutine
    [coroutine] counting 1
main: control is back; coroutine handed me 1

main: one more switch; this time the coroutine returns
main: coroutine finished with: lift-off
```

Every `main → coroutine → main` line pair is one real context switch initiated
from the main flow.
