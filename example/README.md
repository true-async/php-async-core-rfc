# Scheduler examples

Two ways to implement the same cooperative scheduler on the Async Scheduler Hook
API, so the two switching disciplines can be compared side by side.

## [`fibers/`](fibers/) — plain Fibers (asymmetric)

Coroutines are `Fiber`s. The scheduler drives them with `$fiber->resume()`, and a
coroutine yields with `Fiber::suspend()`, which returns to the resumer. Every
switch goes `coroutine → scheduler ({main}) → coroutine`: the scheduler as a central hub, never a
direct coroutine-to-coroutine jump. **Runs on today's engine.**

- [`CooperativeScheduler.php`](fibers/CooperativeScheduler.php) — the driver
- [`interleaving.php`](fibers/interleaving.php) — two coroutines interleave
- [`await.php`](fibers/await.php) — awaiting a value across coroutines (a Future)
- [`nested.php`](fibers/nested.php) — a coroutine spawns a coroutine
- [`fiber_switch_limit.php`](fibers/fiber_switch_limit.php) — the fiber stack rule
  the scheduler-as-hub design exists to avoid

## [`continuation/`](continuation/) — Continuation (symmetric)

The same scheduler via the *additional* `Continuation` API: coroutines are
`Continuation`s (minted by the scheduler through the `createContinuation` mandate
handed to `onLaunch`), and the scheduler switches **directly** into one with
`$coroutine->switchTo()`: one switch, no intermediary. **Runs on today's engine.**

- [`scheduler.php`](continuation/scheduler.php)

See the RFC's *Design rationale* for why `Continuation` halves the switches on the
hot path while staying Xdebug-compatible (it is backed by the engine's own fiber
context).
