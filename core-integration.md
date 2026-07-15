# Scheduler integration in the php-src core

## Zend/zend_async_API.h + zend_async_API.c — the API itself

The key contracts everything else builds on:

- `ZEND_ASYNC_IS_ACTIVE` / `ZEND_ASYNC_ON` — a scheduler is registered and
  running for this request.
- `ZEND_ASYNC_CURRENT_COROUTINE` — the current coroutine (NULL outside of
  coroutines). The core constantly uses it both as a "we are in the async
  world" marker and as a "the destructor suspended" detector (the value
  changed — control flow left and came back).
- `ZEND_ASYNC_SUSPEND()` / `ZEND_ASYNC_RESUME()` / `ZEND_ASYNC_AWAIT()` /
  `ZEND_ASYNC_CANCEL()` / `ZEND_ASYNC_ENQUEUE_COROUTINE()` — the basic
  scheduling operations.
- `zend_coroutine_finish_handler_fn` (line 68) — the coroutine-end handler:
  fires exactly once, no matter how the coroutine ends (return / exception /
  cancellation / bailout unwind). It carries the `waiter`/`data` stored at
  registration time and the `is_bailout` flag ("the scheduler is dying —
  clean up only, schedule nothing"). This is the mechanism GC uses to wait
  for its iterators.
- `zend_coroutine_switch_handler_fn` (line 62) — a synchronous hook on every
  coroutine enter/leave. The shutdown destructor passes use it to notice a
  destructor suspending right at the context switch.
- `ZEND_ASYNC_EXIT_EXCEPTION` — the "exception that terminates the request"
  slot (deadlock, the chain accumulated during the drain). The slot lives in
  the core; the policy of filling it lives in the extension.

---

## main/main.c — the request lifecycle

### main.c:2259 — `php_module_startup()`: `zend_async_globals_ctor()`

What: initializes the Async API globals, next to `gc_globals_ctor()`.
Why: the API slots and `zend_async_globals_t` must exist before extension
MINIT — the scheduler registers itself in the MINIT of test_scheduler /
ext-async.

### main.c:2861 — `php_tsrm_startup_ex()`: TSRM block size

What: `sizeof(zend_async_globals_t)` is accounted for in the preallocated
ZTS globals block. Why: under ZTS all globals live in one aligned block;
without this — an OOB access. (Hence the rule: any change to a globals
struct layout requires `nmake clean`.)

### main.c:2671 — `php_execute_script()`: `ZEND_ASYNC_SCHEDULER_LAUNCH()`

What: before executing the prepend/primary/append files, the core launches
the scheduler. From this moment on, the script is the main coroutine.
Why: the launch must not be lazy (a deliberate decision — TrueAsync
abandoned lazy launch); this is the single launch point. `php -r` does not
go through `php_execute_script` and gets no scheduler — `spawn()` there
throws "The scheduler is not running".

### main.c:2683/2685 — `php_execute_script()`: `ZEND_ASYNC_RUN_SCHEDULER_AFTER_MAIN()`

What: after the main script returns (2683) or bails out (2685, flag true),
control is handed to the scheduler one last time — it finishes off the
remaining coroutines (the drain).
Why: main is over, but parked coroutines are still alive. Their finally
blocks must run while async is still active. Control comes back on the
engine's own context. An exception accumulated by the drain ends up in
EG(exception) and is reported further down the function (2697), like any
other.

### main.c:2000-2007 — `php_request_shutdown()`: the final pass + consuming EG

What: after `zend_call_destructors()` — one more
`ZEND_ASYNC_RUN_SCHEDULER_AFTER_MAIN(false)`: the destructors may have
spawned or resumed coroutines. Then (2004) — if the pass left an exception
in EG(exception), it is reported and released via `zend_exception_error`.

Why the second step is needed — in detail (a port of TrueAsync fix
4538c0619d). Every exception must have an "owner" — code that either
catches it, or reports it as a fatal and releases the object. For the
exceptions the scheduler's drain puts into EG(exception), the owner depends
on which path executed the request:

- **The regular path (a file)**: the script runs through
  `php_execute_script()`. It calls the drain itself (line 2683) and right
  after it reports whatever is left in EG (2697). There is an owner — the
  chain is closed.
- **The `php -r` / embed path**: the code runs through
  `zend_eval_string()`, bypassing `php_execute_script()` — no drain and no
  report there. The first (and only) drain for such a request happens right
  here, in `php_request_shutdown()`. If this drain puts an exception into
  EG (in TrueAsync — a DeadlockError after a deadlock; in our case — e.g. a
  failure to recreate the loop), nothing further down the function ever
  reads EG(exception): the next step is `ZEND_ASYNC_DEACTIVATE` and the
  ordinary shutdown. The exception is neither caught nor reported, the
  object is never released → the user sees no fatal error, and a debug
  build prints "Freeing 0x... (152 bytes)" on exit — a leak.

The guard at 2004 closes the chain for the second path: report + release,
exactly the way `php_execute_script()` does it for the first one. For
requests that arrive here with a clean EG (the norm) it is a no-op.

### main.c:2010 — `php_request_shutdown()`: `ZEND_ASYNC_DEACTIVATE`

What: the point after which everything runs synchronously. The single
deactivation point for the whole request.
Why: what follows is output buffer flushing, module RSHUTDOWN, object
store destruction — no coroutines may exist there.

### main.c:2583 — `php_module_shutdown()`: `zend_async_api_shutdown()`

What: zeroes out all registered API slots when the modules are unloaded.
Why: the extension is being unloaded — no pointer may outlive it.

---

## Zend/zend.c:1983 — `zend_execute_script()`: deferred uncaught report

What: an uncaught exception after `zend_execute()` is reported as a fatal
immediately **only if** `ZEND_ASYNC_CURRENT_COROUTINE == NULL`.
Why: when the script is the main coroutine, an immediate fatal would kill
the request before the drain — the remaining coroutines would never run
their finally blocks. Instead, the exception stays in EG(exception), rides
down to `RUN_SCHEDULER_AFTER_MAIN` in `php_execute_script`, takes part in
the drain (gets folded into the exit chain) and is reported once, at the
end.

---

## Zend/zend_globals.h:171-196 — `zend_shutdown_context_t` in EG

What: a small cursor struct {is_started, coroutine, idx} in the executor
globals. Shared by the two shutdown destructor passes
(`shutdown_destructors()` and the object-store pass) — they run strictly
one after the other.
Why: if a destructor suspends in the middle of a pass, the pass must
continue from the same spot, but in a fresh coroutine. The cursor is core
state, so it lives in EG, not in the extension.

---

## Zend/zend_execute_API.c — `shutdown_destructors()` (the symbol table)

### zend_execute_API.c:284-288 — subscribing the switch handler

What: if the pass runs inside a coroutine, a
`shutdown_destructors_switch_handler` is attached to it via
`ZEND_ASYNC_ADD_SWITCH_HANDLER`.
Why: a destructor inside the pass may suspend. A microtask is no good
here — this late in shutdown the next tick may never come; a hook on the
context switch itself is the only synchronous moment where the suspend can
be noticed.

### zend_execute_API.c:260-280 — the switch handler spawns an iterator

What: on leaving the coroutine (is_enter=false), if the pass has started
and is not finished, an internal iterator coroutine is created via
`ZEND_ASYNC_GC_NEW_COROUTINE()` (its internal_entry continues the pass from
EG(shutdown_context).idx) and enqueued.
Why: a suspended destructor parks the pass's coroutine — the pass itself is
carried on by an independent iterator. The handler returns false — it is
one-shot.

### zend_execute_API.c:332-335 — suspend detection in the loop

What: after each dtor, `coroutine != ZEND_ASYNC_CURRENT_COROUTINE` is
checked — if the current coroutine changed, the pass breaks off (the
spawned iterator will continue it).

---

## Zend/zend_objects_API.c — the object store destructor pass

`zend_objects_store_call_destructors_async()` — the asynchronous variant of
destroying the store's objects at shutdown. The same three-part scheme as
in shutdown_destructors:

### zend_objects_API.c:123-128 — switch handler on the current coroutine
### zend_objects_API.c:93-113 — spawning an iterator coroutine on suspend
### zend_objects_API.c:158-160 — breaking the loop when the coroutine changes

The special part — zend_objects_API.c:139-145: the pass **skips** live
fibers (`zend_ce_fiber`) and objects of the coroutine class
(`ZEND_ASYNC_GET_CE(ZEND_ASYNC_CLASS_COROUTINE)`).
Why: a destructor may spawn a fiber and wait for it; destroying that parked
fiber out from under the waiting destructor would break the destructor
itself. These objects are picked up later, by the ordinary teardown.

---

## Zend/zend_fibers.h / zend_fibers.c — fibers as coroutines

The idea: with an active scheduler, every starting `Fiber` is **adopted** —
its body runs as a coroutine of that scheduler, while the Fiber object
remains a facade. Without a scheduler — the legacy path, byte-for-byte the
old behavior.

### zend_fibers.h:147-154 — the fields in `struct _zend_fiber`

`zend_coroutine_t *coroutine` — the mode marker (NULL = legacy);
`caller_coroutine` — who waits for a yield/finish; `zval transfer` — the
value crossing the suspend/resume boundary. Ownership is one-way: the
coroutine holds a reference to the fiber, the fiber holds none back (apart
from a +1 on the coroutine's zend_object, see below), so no cycle is
created.

### zend_fibers.c:971-1002 — `zend_fiber_adopt()`

What: at fiber start the core offers it to the scheduler via
`ZEND_ASYNC_INTERCEPT_FIBER(fiber)`. The scheduler answers with a coroutine
(or NULL — "keep it", which is how a scheduler keeps its own fibers to
itself). The coroutine gets `internal_entry = zend_fiber_coroutine_entry`,
`extended_data = fiber`, the `ZEND_COROUTINE_SET_FIBER` flag; the fiber
takes +1 on the coroutine's zend_object.

### zend_fibers.c:1005-1022 — `zend_fiber_coroutine_start()`

What: the fiber's body is packed into an fcall (`ZEND_ASYNC_FCALL_DEFINE`),
the coroutine is enqueued (`ZEND_ASYNC_ENQUEUE_COROUTINE`), and the caller
waits for the first yield via `zend_fiber_await()`.

### zend_fibers.c:917-966 — `zend_fiber_await()`

What: the caller records itself in `fiber->caller_coroutine` and parks with
`ZEND_ASYNC_SUSPEND()`. An error/cancellation while parking → FAILURE, with
the prev_execute_data chain severed (otherwise GC walks a dead frame).
Self-await is caught before parking.

### zend_fibers.c:869-913 — `zend_fiber_coroutine_yield()` (Fiber::suspend)

What: the value goes into `fiber->transfer`, the caller is woken with
`ZEND_ASYNC_RESUME`, the fiber itself parks with `ZEND_ASYNC_SUSPEND()`.
After waking up, the fiber re-reads `coroutine->extended_data`: while it
slept, the Fiber object may have been force-closed — then extended_data is
already NULL and a graceful exit flies out.

### zend_fibers.c:840-867 — the body finishing (`zend_fiber_coroutine_entry` tail)

What: an exception that escaped the body is delivered to the caller with
`ZEND_ASYNC_RESUME_WITH_ERROR` (ownership is transferred); a normal finish —
`ZEND_ASYNC_RESUME(caller)`.

### zend_fibers.c:1312-1335 — `Fiber::suspend()` (userland)

What: dispatch on the current coroutine: if there is one and it is a fiber
coroutine — yield through the scheduler; not a fiber — FiberError "Cannot
suspend outside of a fiber"; force-closed or cancelled
(`ZEND_COROUTINE_IS_CANCELLED`) — FiberError "Cannot suspend in a
force-closed fiber". A legacy fiber (active with no coroutine) takes the
old path.

### zend_fibers.c:1385-1398 / 1429 — `Fiber::resume()` / `Fiber::throw()`

What: in coroutine mode — `ZEND_ASYNC_RESUME` /
`ZEND_ASYNC_RESUME_WITH_ERROR` + `zend_fiber_await()` instead of a direct
context switch.

### zend_fibers.c:752-773 — `zend_fiber_release_coroutine()`

What: when the Fiber object dies before its coroutine, the unfinished
coroutine is force-closed: `ZEND_ASYNC_CANCEL(coroutine, graceful_exit,
true)`. Idempotence of a repeated cancel is the scheduler's duty (the first
graceful exit wins).

### zend_fibers.c:1156-1173 — `zend_fiber_object_gc()`

What: a **live** (unfinished) coroutine-mode fiber is a GC root: nothing is
exposed; once `ZEND_COROUTINE_IS_FINISHED` — fci/result/transfer and the
edge to the coroutine's zend_object are exposed.
Why: a live fiber's stack belongs to the scheduler and is torn down at
shutdown; scanning it early either collects it prematurely or miscounts
refcounts over a half-unwound frame (the gh10496 crash). The edge to the
coroutine after finishing is mandatory — without it, the
fiber→coroutine→fcall→closure→fiber cycle was never collected (the gh9916
leak).

### zend_fibers.c:573-603 — error_reporting for the fiber body

What: an empty ini string is treated as "not set" → E_ALL.
Why: the Windows CLI leaves the ini as an empty string (not NULL) — the old
fallback never fired and all uncaught exceptions inside fibers/coroutines
vanished silently. A latent upstream bug, fixed here for both paths (legacy
and coroutine).

---

# GC — Zend/zend_gc.c (its own chapter)

Context: `zend_gc_collect_cycles()` must stay **synchronous** for the
caller (the userland `gc_collect_cycles()` contract), yet destructors may
suspend. The solution — the collection runs in a dedicated GC coroutine and
the caller parks until it finishes; the destructor phase is one more layer
of coroutines. This is a deliberate departure from TrueAsync (whose async
GC returns 0 immediately).

## The state (GC_G, zend_gc.c:300 and nearby)

- `gc_coroutine` — the coroutine of the current GC run;
- `dtor_coroutine` — the current iterator of the destructor phase;
- `dtor_pending` — the count of unfinished iterators;
- `microtask` (zend_gc.c:300) — the re-arming microtask that spawns the
  next iterator;
- `dtor_idx`/`dtor_end` — the cursor over the garbage buffer;
- `gc_collected` — the result for whoever waits.

## zend_gc.c:2201-2237 — `zend_gc_collect_cycles()`: the synchronous wrapper

What: if async is active and the caller is not the GC coroutine:
- a reentrant call while `gc_active` → 0 (deadlock protection: an await
  from inside the destructor phase would wait for itself — the gc_016
  crash);
- `zend_gc_remove_root_tmpvars()` before parking: the run executes on a
  foreign stack and **cannot see** the caller's live TMPVARs — they must be
  shielded (gc_047, bug80072);
- if there is no GC coroutine — `new_gc_coroutine()`:
  `ZEND_ASYNC_GC_NEW_COROUTINE()` + enqueue, the body is
  `zend_gc_coroutine()`;
- the caller blocks in `ZEND_ASYNC_AWAIT(GC_G(gc_coroutine))`
  (zend_gc.c:2223) — the wait list lives on the coroutine itself, no
  global waiter lists. false = the caller was cancelled, not "GC broke";
- the TMPVARs are re-rooted **on both outcomes** — after a normal wake-up
  and when the waiter was cancelled (otherwise the removed roots fall out
  of GC tracking forever — a silent leak; closed by the 2026-07-15 review,
  test 020_gc_await_cancel).

Why `ZEND_ASYNC_GC_NEW_COROUTINE` rather than a plain one: a scheduler may
allocate service coroutines differently (no userland object, etc.); the
fallback is the plain `new_coroutine`.

## The destructor phase as a concurrent iterator (zend_gc.c:2016-2166)

The scheme (the header comment at zend_gc.c:2019-2036):

1. `gc_call_destructors_in_coroutine()` (2149-2166) sets the
   `dtor_idx`/`dtor_end` cursor, spawns the first iterator and parks the GC
   coroutine with `ZEND_ASYNC_SUSPEND()`.
2. `gc_spawn_destructors_coroutine()` (2121-2152): a service coroutine with
   `internal_entry = gc_destructors_coroutine`, `dtor_pending++`, and — the
   key part — a **finish handler** `gc_destructors_finish_handler` via
   `ZEND_ASYNC_ADD_FINISH_HANDLER(coroutine, handler,
   GC_G(gc_coroutine), NULL)` (2134). The waiter here is diagnostic; the
   handler itself works off GC_G. A failed handler registration
   (`handler_id == 0`) or a failed enqueue (2139) takes a common rollback:
   the handler is removed, the counter and `dtor_coroutine` are restored —
   otherwise either the coroutine's end would decrement the counter a
   second time, or nobody would ever decrement it at all (GC would hang).
3. `gc_destructors_coroutine()` (2103-2119): arms the microtask, drives
   `gc_call_destructors`. If a destructor suspended and the microtask has
   already spawned a successor (`dtor_coroutine != CURRENT`) — it quietly
   exits; if it reached the end on its own — it disarms the microtask.
4. The microtask `gc_destructors_iterator_microtask` (2060-2065): on the
   next tick spawns a fresh iterator from the current `dtor_idx`
   (destructors already run are skipped via IS_OBJ_DESTRUCTOR_CALLED).
   Arming/disarming — 2074-2101 (`gc_arm_iterator_microtask` /
   `gc_disarm_iterator_microtask`, refcounting via
   `ZEND_ASYNC_MICROTASK_ADDREF/RELEASE`, scheduling via
   `ZEND_ASYNC_DEFER`).
5. `gc_destructors_finish_handler` (2043-2058): fires exactly once at the
   end of **every** iterator (bailout unwinds included — the finish handler
   contract): `dtor_pending--`; at zero — `dtor_coroutine = NULL` and, if
   not a bailout, `ZEND_ASYNC_RESUME(GC_G(gc_coroutine))` — the GC
   coroutine wakes up and continues the collection. On a bailout there is
   nobody left to wake, but the counter and the globals stay clean.

Why all this complexity: a destructor that suspends forever (waiting for an
event) must not hang GC. Every suspend gives birth to a new iterator; GC
waits until **all** of them finish (dtor_pending), including the ones that
came back from a suspend.

## zend_gc.c:1885-1932 — `gc_call_destructors()`: suspend detection

What: before each dtor the cursor is persisted into `GC_G(dtor_idx)`;
after it — the `coroutine != ZEND_ASYNC_CURRENT_COROUTINE` check (1918):
the coroutine changed → the destructor suspended → the iterator breaks off
(the object goes back into the possible roots); the continuation is the
microtask's business. Symmetric to the old fiber detection (1913,
`dtor_fiber`).

## zend_gc.c:2339-2352 — handling the destructor phase outcome

What: `gc_call_destructors_in_coroutine()` returned false (the parking
fell through) — the GC rerun is cancelled **unconditionally**
(`should_rerun_gc = false`, 2344): a cancelled GC coroutine cannot park
again, and a rerun would only spawn an orphan iterator over an
already-reset buffer (a UAF risk; closed by the 2026-07-15 review, test
021_gc_deadlock). If EG(exception) is an instance of
`ZEND_ASYNC_GET_EXCEPTION_CE(ZEND_ASYNC_EXCEPTION_CANCELLATION)` (the
cancellation class the scheduler provides), it is additionally silenced:
the GC coroutine was cancelled (e.g. a terminal deadlock) — that is not a
GC error. Then the GC_DTOR_GARBAGE tags left behind by the unfinished
iterator are cleaned up.

This is the only place in the core that uses
`ZEND_ASYNC_GET_EXCEPTION_CE` — the core needs the cancellation exception
class without knowing its name.

## A known theoretical hole

GC does not see live TMPVARs on the stacks of **other** parked coroutines:
TrueAsync covers this by exposing parked stacks in the coroutine's get_gc;
our roots model does not. It has not manifested so far; same cluster as the
F_YIELD topic.

---

## Summary table

| File | Lines | What |
|---|---|---|
| main/main.c | 2259, 2861 | API globals: ctor + slot in the TSRM block |
| main/main.c | 2671 | scheduler launch before the script |
| main/main.c | 2683, 2685 | drain after main (normal / bailout) |
| main/main.c | 2000-2007 | drain at request shutdown + consuming EG |
| main/main.c | 2010, 2583 | deactivation; zeroing the API slots |
| Zend/zend.c | 1983 | deferred uncaught report while the drain is still ahead |
| Zend/zend_globals.h | 171-196 | cursor of the shutdown destructor passes |
| Zend/zend_execute_API.c | 260-335 | shutdown_destructors: switch handler + iterator |
| Zend/zend_objects_API.c | 93-160 | same for the object store + skipping fiber/coroutine |
| Zend/zend_fibers.h | 147-154 | coroutine-mode fields in zend_fiber |
| Zend/zend_fibers.c | 971-1022 | fiber adoption, start as a coroutine |
| Zend/zend_fibers.c | 869-966 | yield/await via SUSPEND/RESUME |
| Zend/zend_fibers.c | 1312-1429 | Fiber::suspend/resume/throw dispatch |
| Zend/zend_fibers.c | 752-773 | force-closing the coroutine when the Fiber object dies |
| Zend/zend_fibers.c | 1156-1173 | GC: a live fiber is a root; the coroutine edge after finish |
| Zend/zend_fibers.c | 573-603 | error_reporting: empty string = E_ALL |
| Zend/zend_gc.c | 2201-2237 | synchronous gc_collect_cycles over the GC coroutine; TMPVAR re-root on both outcomes |
| Zend/zend_gc.c | 2016-2170 | destructor phase: iterators + microtask + finish handler |
| Zend/zend_gc.c | 1885-1932 | destructor suspend detection |
| Zend/zend_gc.c | 2339-2358 | phase outcome: no rerun, silencing the cancellation, tag cleanup |
