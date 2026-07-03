# Scheduler Engine Integration

How a scheduler plugs into the PHP engine through the Async Scheduler Hook API.

Reference implementation branch:
[`true-async/php-src` → `async-core-master`](https://github.com/true-async/php-src/tree/async-core-master).

## Registration

A scheduler registers once per process, either from C
(`zend_async_scheduler_register()`, typically at MINIT) or from PHP
(`Async\SchedulerHook::register()`). A second registration fails; from PHP it
throws an Error. `Async\SchedulerHook::getModule()` reports the active driver.

For a PHP-registered scheduler the `launch` hook runs synchronously inside
`register()`, because the engine's own launch point has already passed by the
time userland code executes.

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

## The Switch Primitive

The hooks decide *which* coroutine runs next; the engine performs the switch.
`Async\SchedulerHook::switchTo(Fiber $fiber): mixed` (backed by
`zend_fiber_switch_to_coroutine()`) switches into a fiber bound to a
coroutine, runs it until it yields or finishes, updates the coroutine
lifecycle status, and returns the yielded value. A complete scheduler loop is:
dequeue, `switchTo()`, repeat.

`Fiber::suspend()` needs no coroutine-mode changes: the switch goes back to
the resumer, which on the coroutine path is the scheduler's `switchTo()` call.

## Resulting Request Lifecycle

| Phase | State transition | Actor |
|-------|-----------------|-------|
| MINIT | slots filled via `zend_async_scheduler_register()` | C scheduler |
| Before script code (#1/#5) | `READY → ACTIVE` | core → `launch` |
| During the script | PHP registration also possible: `register()` runs `launch` and activates immediately | bridge |
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
  context switch (`switchTo`), the value transfer (`zend_fiber.transfer`) and
  the coroutine lifecycle status; the hooks own ordering, queues and the
  decision which fibers to adopt.
- **`isBailout` contract.** `suspend(fromMain: true, isBailout: true)` tells
  the scheduler the main flow terminated abnormally, so it can discard
  instead of completing.
