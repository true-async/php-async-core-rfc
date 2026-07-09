# Scheduler Engine Integration

How a scheduler plugs into the PHP engine through the Async Scheduler Hook API.

Reference implementation branch:
[`true-async/php-src` → `async-core`](https://github.com/true-async/php-src/tree/async-core).

## Registration

A scheduler registers once per process, either from C
(`zend_async_scheduler_register()`, typically at MINIT) or from PHP
(`Async\SchedulerHook::register()`). A second registration fails; from PHP it
throws an Error. `Async\SchedulerHook::getModule()` reports the active driver.

`Async\SchedulerHook::register()` takes a factory: it receives the mandate
(createContinuation, currentContinuation, currentCoroutine) and returns the
scheduler, constructed already holding it. The factory runs synchronously
inside `register()`, because the engine's own launch point has already passed
by the time userland code executes; the `launch` slot itself is therefore not
bridged to PHP and stays a C-scheduler concern.

## Engine Invocation Points

| # | Location | Hook | Purpose |
|---|----------|------|---------|
| 1 | `main/main.c`, `php_execute_script_ex()`, before prepend/primary/append scripts | `launch` | The scheduler is **not lazy**: it always starts right before the script code runs. |
| 2 | `main/main.c`, `php_execute_script_ex()`, after all scripts complete | `suspend(fromMain: true, isBailout: false)` | The main script finished normally; the scheduler runs the remaining coroutines to completion. |
| 3 | `main/main.c`, `php_execute_script_ex()`, inside `zend_catch` | `suspend(fromMain: true, isBailout: true)` | The main flow ended with a bailout (`exit()`, fatal error); the scheduler decides the fate of the remaining coroutines. |
| 4 | `main/main.c`, `php_request_shutdown()`, after `zend_call_destructors()` | `suspend(fromMain: true, isBailout: false)`, then the Async state is deactivated | Last handover before the request dies: destructors may have spawned coroutines. Everything past this point is synchronous-only. |
| 5 | `sapi/phpdbg/phpdbg_prompt.c`, around `zend_execute()` | `launch` before, `suspend(fromMain: true, ...)` after | The same pair for the phpdbg SAPI. |
| 6 | `Zend/zend_fibers.c`, `Fiber::start()` | `intercept_fiber` | The engine asks the scheduler for a coroutine to bind to the starting fiber. A coroutine puts the fiber on the coroutine path; `null` keeps it low-level. |
| 7 | `Zend/zend_fibers.c`, `Fiber::start()/resume()/throw()` on a bound fiber | `enqueue_coroutine` / `resume`, then `suspend(fromMain: false, ...)` | The fiber operation becomes scheduler policy: park the value (or exception), notify, hand over. The call returns the value the fiber yields. |
| 8 | `Zend/zend_gc.c`, the destructor phase of `gc_collect_cycles()` | `gc_destructors` *(C-only)* | The around-interceptor for the destructor phase. **Not a PHP-registerable hook**: it fires at the latest stage of the request (teardown of globals and the object store), where userland is already being dismantled and no PHP scheduler can be safely re-entered, so only a C-implemented scheduler (or the engine itself) may intercept it. It brackets the engine's destructor executor (open a completion group, run, await everything the destructors spawned). A safety net re-runs missed destructors afterwards; userland `__destruct` always takes the classic synchronous path. |
| 9 | anywhere in the engine or an extension | `defer` (slot) / `DEFER` (hook) | Queue a one-shot microtask on the scheduler's queue. The engine stores nothing: `SchedulerHook::defer()` and C consumers route the task to the provider. |

## Microtasks

The microtask queue is owned by the scheduler, not by the engine. `SchedulerHook::defer()`
(PHP) and `ZEND_ASYNC_DEFER()` (C, a thin refcounted task structure with a cancel flag) forward
tasks to the scheduler's DEFER implementation; the scheduler stores them and runs each exactly
once on its next tick. A cancelled task's handler is never invoked; its destructor releases the
container when the last reference dies.

## Switching

The hooks decide *which* coroutine runs next; the engine performs the switch.
There is no switching API. Inside scheduler code (any hook invocation, marked
by the in_scheduler_context flag in the async globals), the plain Fiber API on
a bound fiber performs the direct context switch: `$fiber->resume()` runs the
fiber until it yields or finishes and updates the coroutine lifecycle status.
In application code the same calls park the value and route through the hooks
instead. The flag is cleared while application code runs: the fiber body, and
each destructor invoked during the GC phase.

`Fiber::suspend()` needs no coroutine-mode changes: the switch goes back to
the resumer, which on the coroutine path is the scheduler's own resume call.

## Error Channel

The PHP hooks report failure only by throwing; where a hook returns `bool`,
the value is data (`onEnqueue`: accepted or not, `contextUnset`: key existed).
The C slots stay `bool`: C has no exceptions, and a reactor callback needs a
cheap answer without stack unwinding. The registration bridge maps between
the two: a PHP hook that throws makes the slot report failure with the
exception left pending in `EG(exception)`. A slot failure *without* a pending
exception is a quiet rejection (enqueue during shutdown); the engine converts
it into a thrown `Error` at PHP-visible boundaries (`Fiber::resume()` on an
adopted fiber) and leaves C callers to observe the `false`, dispose of any
error they were delivering, and treat the coroutine as never scheduled.

## Coroutine Switch Handlers (C-only)

A per-coroutine vector of C callbacks fired when the coroutine is entered,
left, or finished (`zend_coroutine_add_switch_handler()`,
`ZEND_COROUTINE_ENTER/LEAVE/FINISH`), plus a process-wide list applied to the
main coroutine at its adoption
(`zend_async_add_main_coroutine_start_handler()`). This seam is **not part of
the PHP interface** and is not bridged: for a PHP scheduler the same needs are
covered by the microtask queue (the watchdog/concurrent-iterator pattern) and
the internal context (per-coroutine state, migrated lazily). The C vector
exists for engine subsystems and extensions that must observe every switch
(profilers, debuggers, the shutdown-destructor watchdogs in the full
TrueAsync tree).

## Fork Guard

`fork()` cannot preserve a live scheduler: parked coroutines, watcher fds and
worker threads do not survive it. `pcntl_fork()` asks the engine with
`ZEND_ASYNC_BEFORE_FORK()`; while the Async state is active and no fork hooks
are registered, the answer is a thrown `Error`, unconditionally. A C extension
that can survive a fork registers the pair
`zend_async_fork_register(before_fork, after_fork_child)`: `before_fork()`
runs in the parent and decides whether this fork is allowed (the reference
scheduler permits it only when the main coroutine is the sole live one;
it throws and returns `false` otherwise), `after_fork_child()` reinitialises
the reactor in the child. With the Async state off, forking is unrestricted.

## Resulting Request Lifecycle

| Phase | State transition | Actor |
|-------|-----------------|-------|
| MINIT | slots filled via `zend_async_scheduler_register()` | C scheduler |
| Before script code (#1/#5) | `READY → ACTIVE` | core → `launch` |
| During the script | PHP registration also possible: `register()` runs the scheduler factory and activates immediately | bridge |
| Script runs | fibers adopted via `intercept_fiber`; operations route through the hooks | scheduler |
| After main (#2/#3/#5) | remaining coroutines drain | core → `suspend(fromMain: true)` |
| Destructors done (#4) | final drain, then `ACTIVE → OFF` | core |
| Shutdown | abandoned suspended managed fibers are destroyed by the regular fiber teardown; the coroutine handle is disposed with the fiber | engine |

## Design Notes

- **No lazy initialization.** There are no scheduler-init checks on IO paths:
  by the time any IO happens, the scheduler is already running.
- **Zero cost without a provider.** Every invocation point is a guarded no-op
  while the Async state is off; PHP without a scheduler behaves exactly as
  before.
- **Policy vs mechanism.** Hooks never manipulate stacks. The engine owns the
  context switch (the direct Fiber path in scheduler context), the value
  transfer (`zend_fiber.transfer`) and
  the coroutine lifecycle status; the hooks own ordering, queues and the
  decision which fibers to adopt.
- **`isBailout` contract.** `suspend(fromMain: true, isBailout: true)` tells
  the scheduler the main flow terminated abnormally, so it can discard
  instead of completing.
