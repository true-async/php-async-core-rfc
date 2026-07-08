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

The same scheduler via the *additional* `Continuation` API, in the RFC's two
layers: a `Continuation` (minted through the `createContinuation` mandate handed
to `onLaunch`) is the switch primitive, and the scheduler wraps it into its own
`Coroutine` class, the schedulable unit the ready queue holds. The scheduler
switches **directly** into one with `$coroutine->continuation->switchTo()`: one
switch, no intermediary. The main flow is normalised the same way: at its first
yield it is captured via the `currentContinuation` mandate and becomes a
`Coroutine` too (`isMain`), which `onSuspend()` returns as the current one.
**Targets the RFC mandate with three closures** (the PoC bridge currently hands
two: `currentContinuation` is pending there).

- [`scheduler.php`](continuation/scheduler.php)

See the RFC's *Design rationale* for why `Continuation` halves the switches on the
hot path while staying Xdebug-compatible (it is backed by the engine's own fiber
context).
