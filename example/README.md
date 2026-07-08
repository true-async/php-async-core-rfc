# Scheduler examples

The same cooperative scheduler, written twice: on plain `Fiber`s and on the `Continuation`
primitive. Side by side they show the two switching disciplines the
[Async Scheduler Hook API](../scheduler_rfc.md) supports.

Every example runs on the
[`async-core`](https://github.com/true-async/php-src/tree/async-core) proof-of-concept build:

```sh
sapi/cli/php example/fibers/interleaving.php
sapi/cli/php example/continuation/scheduler.php
```

## The two disciplines at a glance

|                        | [`fibers/`](fibers/)                   | [`continuation/`](continuation/)  |
|------------------------|----------------------------------------|-----------------------------------|
| Switch primitive       | `Fiber` (asymmetric)                   | `Continuation` (symmetric)        |
| A yield lands in       | the resumer: always back in the hub    | wherever the scheduler points     |
| Switches per hand-off  | two: `coroutine → scheduler → coroutine` | one: `coroutine → coroutine`    |
| Model                  | the scheduler as a central hub         | direct jumps, no intermediary     |

## [`fibers/`](fibers/): the scheduler as a hub

Coroutines are `Fiber`s. The scheduler drives them with `$fiber->resume()`, and a coroutine
yields with `Fiber::suspend()`, which always returns to the resumer. Every hand-off therefore
routes through the scheduler.

- [`CooperativeScheduler.php`](fibers/CooperativeScheduler.php): the driver all fiber examples share
- [`interleaving.php`](fibers/interleaving.php): two coroutines interleave
- [`await.php`](fibers/await.php): awaiting a value across coroutines (a Future)
- [`nested.php`](fibers/nested.php): a coroutine spawns a coroutine
- [`fiber_switch_limit.php`](fibers/fiber_switch_limit.php): the fiber stack rule the hub
  design exists to avoid

## [`continuation/`](continuation/): direct symmetric switching

The same scheduler in the RFC's two layers:

- a **`Continuation`**, minted through the `createContinuation` mandate handed to `onLaunch()`,
  is the low-level switch primitive;
- a **`Coroutine`**, the scheduler's own class, wraps its Continuation and is what the ready
  queue holds; switching is a direct jump: `$coroutine->continuation->switchTo()`.

The main flow gets the same treatment: at its first yield it is captured through the
`currentContinuation` mandate and becomes a `Coroutine` too (`isMain`), which `onSuspend()`
returns as the current one. This is the normalisation the RFC describes in
"The main flow is a coroutine too".

- [`scheduler.php`](continuation/scheduler.php): the full scheduler, with working microtasks,
  contexts and main normalisation

Why a second discipline at all? `Continuation` halves the switches on hot paths while staying
Xdebug-compatible (it is backed by the engine's own fiber context); see the RFC's
*Design rationale*.
