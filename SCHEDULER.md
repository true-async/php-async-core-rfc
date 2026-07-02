# Scheduler Engine Integration

How a scheduler implementation plugs into the PHP engine through the thin
Async Core ABI (`Zend/zend_async_API.h`).

Reference implementation branch:
[`true-async/php-src` → `async-core`](https://github.com/true-async/php-src/tree/async-core).

## Engine Integration Points

| # | Location | Hook | Trigger condition | Purpose |
|---|----------|------|-------------------|---------|
| 1 | `main/main.c` — `php_execute_script_ex()`, before prepend/primary/append scripts | `ZEND_ASYNC_SCHEDULER_LAUNCH()` | `ZEND_ASYNC_IS_READY` (a scheduler module has registered itself) | The scheduler is **not lazy**: it always starts right before the script code runs. The main flow becomes the main coroutine. |
| 2 | `main/main.c` — `php_execute_script_ex()`, after all scripts complete | `ZEND_ASYNC_RUN_SCHEDULER_AFTER_MAIN(false)` | `ZEND_ASYNC_IS_ACTIVE` (built into the macro) | The main script has finished normally; control is handed to the scheduler so the remaining coroutines can run to completion. |
| 3 | `main/main.c` — `php_execute_script_ex()`, inside `zend_catch` | `ZEND_ASYNC_RUN_SCHEDULER_AFTER_MAIN(true)` | `ZEND_ASYNC_IS_ACTIVE` | The main flow ended with a bailout (`exit()`, fatal error); the scheduler gets a chance to handle it and finalize coroutines correctly. |
| 4 | `main/main.c` — `php_request_shutdown()`, after `zend_call_destructors()` | `ZEND_ASYNC_RUN_SCHEDULER_AFTER_MAIN(false)` then `ZEND_ASYNC_DEACTIVATE` | `ZEND_ASYNC_IS_ACTIVE` | Last handoff before the request dies: object destructors may have spawned coroutines. Afterwards the Async API is switched off — everything past this point is synchronous-only. |
| 5 | `sapi/phpdbg/phpdbg_prompt.c` — before `zend_execute()` | `ZEND_ASYNC_SCHEDULER_LAUNCH()` | `ZEND_ASYNC_IS_READY` | Same as #1 for the phpdbg SAPI. |
| 6 | `sapi/phpdbg/phpdbg_prompt.c` — after `zend_execute()` | `ZEND_ASYNC_RUN_SCHEDULER_AFTER_MAIN(false)` | `ZEND_ASYNC_IS_ACTIVE` | Same as #2 for the phpdbg SAPI. |

## Resulting Request Lifecycle

| Phase | State transition | Actor |
|-------|-----------------|-------|
| MINIT | slots filled via `zend_async_scheduler_register()` | scheduler module |
| RINIT | `OFF → READY` (`ZEND_ASYNC_INITIALIZE`) | scheduler module |
| Before script code (#1/#5) | `READY → ACTIVE`, main flow becomes the main coroutine | core → `launch` slot |
| Script runs | coroutines spawn/suspend/resume freely | scheduler |
| After main (#2/#3/#6) | remaining coroutines drain | core → `suspend(from_main=true, is_bailout)` slot |
| Destructors done (#4) | final drain, then `ACTIVE → OFF` (`ZEND_ASYNC_DEACTIVATE`) | core |

## Design Notes

- **No lazy initialization.** The former TrueAsync `ZEND_ASYNC_SCHEDULER_INIT()`
  checks scattered across IO paths (`plain_wrapper.c` ×3, `exec.c` ×3,
  `zend_fibers.c`) do not exist in this ABI: by the time any IO happens, the
  scheduler is already running.
- **Zero cost without a provider.** Both macros are guarded no-ops while the
  API is `OFF`; a PHP build without an async module executes exactly the same
  instructions as before, minus two branch checks per request.
- **`is_bailout` contract.** `suspend(from_main=true, is_bailout=true)` tells
  the scheduler the main flow terminated abnormally, so it can cancel instead
  of resume, mirroring TrueAsync semantics.
