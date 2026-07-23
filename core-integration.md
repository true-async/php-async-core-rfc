# Scheduler integration in the php-src core

## Zend/zend_async_API.h + zend_async_API.c: the API itself

The key contracts everything else builds on:

- `ZEND_ASYNC_IS_ACTIVE` / `ZEND_ASYNC_ON`: a scheduler is registered and
  running for this request.
- `ZEND_ASYNC_CURRENT_COROUTINE`: the current coroutine, NULL outside of
  coroutines. The core uses it both as a "we are in the async world" marker
  and as a suspend detector: if the value changed, control flow left and
  came back.
- `ZEND_ASYNC_SUSPEND()`, `ZEND_ASYNC_ENQUEUE_COROUTINE()`,
  `ZEND_ASYNC_ENQUEUE_WITH_ERROR()`, `ZEND_ASYNC_AWAIT()`,
  `ZEND_ASYNC_CANCEL()`: the basic scheduling operations. There is no
  separate resume. Enqueuing a fresh coroutine and resuming a suspended one
  are one operation, and the error parameter of enqueue is the delivery
  channel for cancellation and IO/timeout failures, raised at the
  suspension point.
- `await` stays a **slot** rather than a series of finish-handler and
  suspend calls, because awaiting is a scheduling decision: the provider
  owns the waiter bookkeeping (it lives on the awaited coroutine), may wake
  the waiter with a direct symmetric switch instead of the run queue, and
  marks the outcome as observed, so an awaited exception is not "unhandled".
- `zend_coroutine_finish_handler_fn`: the coroutine-end handler. It fires
  exactly once, no matter how the coroutine ends (return, exception,
  cancellation, bailout unwind), and carries the `waiter`/`data` stored at
  registration time plus the `is_bailout` flag ("the scheduler is dying,
  clean up only, schedule nothing"). This is the mechanism GC uses to wait
  for its iterators.
- `zend_coroutine_switch_handler_fn`: a synchronous hook on every coroutine
  enter and leave. The shutdown destructor passes use it to notice a
  destructor suspending right at the context switch.
- `zend_coroutine_awaiting_info_fn`: the awaiting-info vector. Whoever
  suspends a coroutine registers a handler+data pair describing one thing
  it waits for (`ZEND_ASYNC_ADD_AWAITING_INFO`); the descriptions are
  collected with `ZEND_ASYNC_GET_AWAITING_INFO` as a packed array of
  strings, and the scheduler wipes the whole vector when the coroutine is
  enqueued: every wait description dies with the wait. Diagnostics only
  (wait graph, deadlock reports); replaces the old single `awaiting_info`
  field and the `wait_info` string slot.
- `ZEND_ASYNC_EXIT_EXCEPTION`: the slot for the exception that terminates
  the request (a deadlock, the chain accumulated during the drain). The
  slot lives in the core; the policy of filling it lives in the extension.

---

## main/main.c: the request lifecycle

### main.c:2259, `php_module_startup()`: `zend_async_globals_ctor()`

Initializes the Async API globals, next to `gc_globals_ctor()`. The API
slots and `zend_async_globals_t` must exist before extension MINIT, because
the scheduler registers itself in the MINIT of test_scheduler / ext-async.

### main.c:2859, `php_tsrm_startup_ex()`: TSRM block size

`sizeof(zend_async_globals_t)` is accounted for in the preallocated ZTS
globals block. Under ZTS all globals live in one aligned block; without
this the access is out of bounds. Hence the rule: any change to a globals
struct layout requires `nmake clean`.

### main.c:2671, `php_execute_script()`: `ZEND_ASYNC_SCHEDULER_LAUNCH()`

Before executing the prepend/primary/append files, the core launches the
scheduler. From this moment on the script is the main coroutine. The launch
is deliberately not lazy (TrueAsync abandoned lazy launch); this is the
single launch point. `php -r` does not go through `php_execute_script` and
gets no scheduler, so `spawn()` there throws "The scheduler is not
running".

### main.c:2683/2685, `php_execute_script()`: `ZEND_ASYNC_RUN_SCHEDULER_AFTER_MAIN()`

After the main script returns (2683) or bails out (2685, flag true),
control is handed to the scheduler one last time to finish off the
remaining coroutines: the drain. Main is over, but parked coroutines are
still alive, and their finally blocks must run while async is still active.
Control comes back on the engine's own context. An exception accumulated by
the drain ends up in EG(exception) and is reported further down the
function (2697), like any other.

### main.c:2000-2007, `php_request_shutdown()`: the final pass, consuming EG

After `zend_call_destructors()` comes one more
`ZEND_ASYNC_RUN_SCHEDULER_AFTER_MAIN(false)`: the destructors may have
spawned or resumed coroutines. Then (2004), if the pass left an exception
in EG(exception), it is reported and released via `zend_exception_error`.

Why the second step is needed (a port of TrueAsync fix 4538c0619d): every
exception must have an owner, code that either catches it or reports it as
a fatal and releases the object. For the exceptions the scheduler's drain
puts into EG(exception), the owner depends on which path executed the
request.

- **The regular path (a file).** The script runs through
  `php_execute_script()`, which calls the drain itself (2683) and right
  after reports whatever is left in EG (2697). There is an owner; the chain
  is closed.
- **The `php -r` / embed path.** The code runs through
  `zend_eval_string()`, bypassing `php_execute_script()`, so neither the
  drain nor the report happens there. The first and only drain for such a
  request happens right here, in `php_request_shutdown()`. If it puts an
  exception into EG (in TrueAsync a DeadlockError; in our case e.g. a
  failure to recreate the loop), nothing further down the function ever
  reads EG(exception): the next step is `ZEND_ASYNC_DEACTIVATE` and the
  ordinary shutdown. The exception is neither caught nor reported, the
  object is never released, the user sees no fatal error, and a debug build
  prints "Freeing 0x... (152 bytes)" on exit. A leak.

The guard at 2004 closes the chain for the second path with the same
report-and-release `php_execute_script()` performs for the first one. For
requests that arrive here with a clean EG (the norm) it is a no-op.

### main.c:2010, `php_request_shutdown()`: `ZEND_ASYNC_DEACTIVATE`

The point after which everything runs synchronously; the single
deactivation point for the whole request. What follows is output buffer
flushing, module RSHUTDOWN and object store destruction, where no
coroutines may exist.

### main.c:2583, `php_module_shutdown()`: `zend_async_api_shutdown()`

Zeroes out all registered API slots when the modules are unloaded. The
extension is going away; no pointer may outlive it.

---

## Zend/zend.c:1986, `zend_execute_script()`: deferred uncaught report

An uncaught exception after `zend_execute()` is reported as a fatal
immediately **only if** `ZEND_ASYNC_CURRENT_COROUTINE == NULL`. When the
script is the main coroutine, an immediate fatal would kill the request
before the drain and the remaining coroutines would never run their finally
blocks. Instead the exception stays in EG(exception), rides down to
`RUN_SCHEDULER_AFTER_MAIN` in `php_execute_script`, takes part in the drain
(folded into the exit chain) and is reported once, at the end.

---

## Zend/zend_globals.h:172-188: `zend_shutdown_context_t` in EG

A small cursor struct {is_started, coroutine, idx} in the executor globals,
shared by the two shutdown destructor passes (`shutdown_destructors()` and
the object-store pass), which run strictly one after the other. If a
destructor suspends in the middle of a pass, the pass must continue from
the same spot, but in a fresh coroutine. The cursor is core state, so it
lives in EG, not in the extension.

---

## Zend/zend_execute_API.c: `shutdown_destructors()` (the symbol table)

### zend_execute_API.c:284-289: subscribing the switch handler

If the pass runs inside a coroutine, a
`shutdown_destructors_switch_handler` is attached to it via
`ZEND_ASYNC_ADD_SWITCH_HANDLER`. A destructor inside the pass may suspend,
and a microtask is no good here: this late in shutdown the next tick may
never come. A hook on the context switch itself is the only synchronous
moment where the suspend can be noticed.

### zend_execute_API.c:258-282: the switch handler spawns an iterator

On leaving the coroutine (is_enter=false), if the pass has started and is
not finished, an internal iterator coroutine is created via
`ZEND_ASYNC_GC_NEW_COROUTINE()` (its internal_entry continues the pass from
EG(shutdown_context).idx) and enqueued. A suspended destructor parks the
pass's coroutine, so the pass itself is carried on by an independent
iterator. The handler returns false: it is one-shot.

### zend_execute_API.c:334-337: suspend detection in the loop

After each destructor the pass checks
`coroutine != ZEND_ASYNC_CURRENT_COROUTINE`. If the current coroutine
changed, the pass breaks off and the spawned iterator continues it.

---

## Zend/zend_objects_API.c: the object store destructor pass

`zend_objects_store_call_destructors_async()` is the asynchronous variant
of destroying the store's objects at shutdown, built on the same
three-part scheme as shutdown_destructors:

- zend_objects_API.c:123-128: switch handler on the current coroutine;
- zend_objects_API.c:93-113: spawning an iterator coroutine on suspend;
- zend_objects_API.c:158-160: breaking the loop when the coroutine changes.

The special part (zend_objects_API.c:142-151): **only while a scheduler is
active** the pass skips live fibers (`zend_ce_fiber`) and objects of the
coroutine class (`ZEND_ASYNC_GET_CE(ZEND_ASYNC_CLASS_COROUTINE)`). A
destructor may spawn a fiber and wait for it, and destroying that parked
fiber out from under the waiting destructor would break the destructor
itself; the drain picks these objects up later. Without a scheduler the
skip must NOT apply: the upstream contract relies on the destructor pass
force-closing parked fibers. The legacy GC destructor fiber is released
exactly this way, and an unconditional skip leaked it.

---

## Zend/zend_fibers.h / zend_fibers.c: fibers as coroutines

The idea: with an active scheduler, every starting `Fiber` is **adopted**.
Its body runs as a coroutine of that scheduler while the Fiber object
remains a facade. Without a scheduler the legacy path applies,
byte-for-byte the old behavior.

### zend_fibers.h:147-154: the fields in `struct _zend_fiber`

`zend_coroutine_t *coroutine` is the mode marker (NULL = legacy);
`caller_coroutine` is who waits for a yield or finish; `zval transfer`
carries the value across the suspend/resume boundary. Ownership is one-way:
the coroutine holds a reference to the fiber, the fiber holds none back
(apart from a +1 on the coroutine's zend_object, see below), so no cycle is
created.

### zend_fibers.c:988-1019: `zend_fiber_adopt()`

At fiber start the core offers it to the scheduler via
`ZEND_ASYNC_INTERCEPT_FIBER(fiber)`. The scheduler answers with a coroutine,
or with NULL ("keep it"), which is how a scheduler keeps its own fibers to
itself. The coroutine gets `internal_entry = zend_fiber_coroutine_entry`,
`extended_data = fiber` and the `ZEND_COROUTINE_SET_FIBER` flag; the fiber
takes +1 on the coroutine's zend_object.

### zend_fibers.c:1022-1039: `zend_fiber_coroutine_start()`

The fiber's body is packed into an fcall (`ZEND_ASYNC_FCALL_DEFINE`), the
coroutine is enqueued (`ZEND_ASYNC_ENQUEUE_COROUTINE`), and the caller
waits for the first yield via `zend_fiber_await()`.

### zend_fibers.c:934-983: `zend_fiber_await()`

The caller records itself in `fiber->caller_coroutine` and parks with
`ZEND_ASYNC_SUSPEND()`. An error or cancellation while parking yields
FAILURE, with the prev_execute_data chain severed (otherwise GC walks a
dead frame). Self-await is caught before parking.

### zend_fibers.c:882-930: `zend_fiber_coroutine_yield()` (Fiber::suspend)

The value goes into `fiber->transfer`, the caller is woken with
`ZEND_ASYNC_ENQUEUE_COROUTINE`, and the fiber parks with
`ZEND_ASYNC_SUSPEND()`. After waking up, the fiber re-reads
`coroutine->extended_data`: while it slept, the Fiber object may have been
force-closed, in which case extended_data is already NULL and a graceful
exit flies out.

### zend_fibers.c:848-878: the body finishing (`zend_fiber_coroutine_entry` tail)

An exception that escaped the body is delivered to the caller with
`ZEND_ASYNC_ENQUEUE_WITH_ERROR` (ownership is transferred); a normal finish
wakes the caller with `ZEND_ASYNC_ENQUEUE_COROUTINE`.

`exit()` is the one exception delivered nowhere: at 852-853 an unwind exit
raises `ZEND_ASYNC_SHUTDOWN()`, the only call site of the shutdown
notification in the tree. The test is `zend_is_unwind_exit`, not
`zend_is_graceful_exit`: a graceful exit is the disposal unwind of a
force-closed fiber and must shut nothing down. `exit()` in the main flow
never reaches this tail; main leaves through the end-of-main handover with
the bailout flag (main.c:2685), so the notification stays specific to a
coroutine that ends the request from inside. Tests: 064-067 in
ext/test_scheduler/tests.

### zend_fibers.c:1346-1404: `Fiber::suspend()` (userland)

Dispatch on the current coroutine. If there is one and it is a fiber
coroutine, yield through the scheduler; not a fiber, FiberError "Cannot
suspend outside of a fiber"; force-closed or cancelled
(`ZEND_COROUTINE_IS_CANCELLED`), FiberError "Cannot suspend in a
force-closed fiber". A legacy fiber (active with no coroutine) takes the
old path.

### zend_fibers.c:1406 / 1450: `Fiber::resume()` / `Fiber::throw()`

In coroutine mode: `ZEND_ASYNC_ENQUEUE_COROUTINE` /
`ZEND_ASYNC_ENQUEUE_WITH_ERROR` plus `zend_fiber_await()` instead of a
direct context switch.

### zend_fibers.c:757-778: `zend_fiber_release_coroutine()`

When the Fiber object dies before its coroutine, the unfinished coroutine
is force-closed: `ZEND_ASYNC_CANCEL(coroutine, graceful_exit, true)`.
Idempotence of a repeated cancel is the scheduler's duty; the first
graceful exit wins.

### zend_fibers.c: `zend_fiber_object_gc()`

Three states of a coroutine-mode fiber, three policies:

- **Running, or awaiting inside the body.** A GC root: nothing is exposed.
  The stack is mid-operation, and scanning it miscounts refcounts over a
  half-unwound frame (the gh10496 crash).
- **Parked at a clean `Fiber::suspend()`.** fci/transfer are exposed and
  the frames of the parked stack are walked (`zend_fiber_frames_gc`, shared
  with the legacy path; generator frames included via
  `zend_generator_frame_gc`). This is the analog of TrueAsync's
  `ZEND_COROUTINE_F_YIELD`-gated walk; our yield marker is
  `fiber->context.status`, which only the yield path touches. It is what
  lets an abandoned yielded fiber, and a generator parked on its stack,
  collect before shutdown (the upstream gh9735/gh10340 contract).
- **Finished.** fci/result/transfer and the edge to the coroutine's
  zend_object are exposed. The edge is mandatory: without it the
  fiber→coroutine→fcall→closure→fiber cycle was never collected (the gh9916
  leak).

The coroutine object itself is never put into the buffer while unfinished:
the scheduler holds references GC cannot see. To make the walk safe, the
yield path severs `stack_bottom->prev_execute_data` **before** parking, so
the walked chain ends at the fiber's own root frame instead of running on
into the caller's live frames.

### zend_fibers.c: `zend_fiber_vm_stack_start()` / `zend_fiber_vm_stack_free()`

The VM-stack setup and teardown of a fiber body, factored out and exported
(`ZEND_API`): the legacy `zend_fiber_execute` and the bridge extension's
coroutine bodies run on stacks installed by the same helper. Inside it
lives the error_reporting fix: an empty ini string is treated as "not set"
and falls back to E_ALL. The Windows CLI leaves the ini as an empty string
(not NULL), so the old fallback never fired and all uncaught exceptions
inside fibers and coroutines vanished silently. A latent upstream bug,
fixed for both paths.

---

# GC: Zend/zend_gc.c (its own chapter)

Context: `zend_gc_collect_cycles()` must stay **synchronous** for the
caller (the userland `gc_collect_cycles()` contract), yet destructors may
suspend. The solution: the collection runs in a dedicated GC coroutine and
the caller parks until it finishes; the destructor phase is one more layer
of coroutines. This is a deliberate departure from TrueAsync, whose async
GC returns 0 immediately.

## The state (GC_G, zend_gc.c:300 and nearby)

- `gc_coroutine`: the coroutine of the current GC run;
- `dtor_coroutine`: the current iterator of the destructor phase;
- `dtor_pending`: the count of unfinished iterators;
- `microtask`: the re-arming microtask that spawns the next iterator;
- `dtor_idx`/`dtor_end`: the cursor over the garbage buffer;
- `gc_collected`: the result for whoever waits.

## zend_gc.c:2201-2237, `zend_gc_collect_cycles()`: the synchronous wrapper

If async is active and the caller is not the GC coroutine:

- a reentrant call while `gc_active` returns 0 (deadlock protection: an
  await from inside the destructor phase would wait for itself, the gc_016
  crash);
- `zend_gc_remove_root_tmpvars()` runs before parking: the run executes on
  a foreign stack and **cannot see** the caller's live TMPVARs, so they
  must be shielded (gc_047, bug80072);
- if there is no GC coroutine, `new_gc_coroutine()` creates one:
  `ZEND_ASYNC_GC_NEW_COROUTINE()` plus enqueue, the body is
  `zend_gc_coroutine()`;
- the caller blocks in `ZEND_ASYNC_AWAIT(GC_G(gc_coroutine))`. The wait
  list lives on the coroutine itself; there are no global waiter lists.
  false means the caller was cancelled, not that GC broke;
- the TMPVARs are re-rooted **on both outcomes**, after a normal wake-up
  and when the waiter was cancelled. Otherwise the removed roots fall out
  of GC tracking forever, a silent leak (closed by the 2026-07-15 review,
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
   `internal_entry = gc_destructors_coroutine`, `dtor_pending++`, and the
   key part, a **finish handler** `gc_destructors_finish_handler`
   registered via `ZEND_ASYNC_ADD_FINISH_HANDLER(coroutine, handler,
   GC_G(gc_coroutine), NULL)` (2134). The waiter there is diagnostic; the
   handler itself works off GC_G. A failed handler registration
   (`handler_id == 0`) or a failed enqueue (2139) takes a common rollback
   that removes the handler and restores the counter and `dtor_coroutine`;
   otherwise either the coroutine's end would decrement the counter a
   second time, or nobody would ever decrement it at all and GC would hang.
3. `gc_destructors_coroutine()` (2103-2119) arms the microtask and drives
   `gc_call_destructors`. If a destructor suspended and the microtask has
   already spawned a successor (`dtor_coroutine != CURRENT`), it quietly
   exits; if it reached the end on its own, it disarms the microtask.
4. The microtask `gc_destructors_iterator_microtask` (2060-2065) spawns a
   fresh iterator on the next tick from the current `dtor_idx` (destructors
   already run are skipped via IS_OBJ_DESTRUCTOR_CALLED). Arming and
   disarming live at 2074-2101, refcounting via
   `ZEND_ASYNC_MICROTASK_ADDREF/RELEASE`, scheduling via
   `ZEND_ASYNC_DEFER`.
5. `gc_destructors_finish_handler` (2043-2058) fires exactly once at the
   end of **every** iterator, bailout unwinds included (the finish handler
   contract): `dtor_pending--`; at zero, `dtor_coroutine = NULL` and, if
   not a bailout, `ZEND_ASYNC_ENQUEUE_COROUTINE(GC_G(gc_coroutine))` wakes
   the GC coroutine to continue the collection. On a bailout there is
   nobody left to wake, but the counter and the globals stay clean.

Why all this complexity: a destructor that suspends forever (waiting for an
event) must not hang GC. Every suspend gives birth to a new iterator, and
GC waits until **all** of them finish (dtor_pending), including the ones
that came back from a suspend.

## zend_gc.c:1885-1932, `gc_call_destructors()`: suspend detection

Before each destructor the cursor is persisted into `GC_G(dtor_idx)`; after
it comes the `coroutine != ZEND_ASYNC_CURRENT_COROUTINE` check (1918). If
the coroutine changed, the destructor suspended: the iterator breaks off
(the object goes back into the possible roots) and the continuation is the
microtask's business. Symmetric to the old fiber detection (1913,
`dtor_fiber`).

## zend_gc.c:2339-2352: handling the destructor phase outcome

When `gc_call_destructors_in_coroutine()` returns false (the parking fell
through), the GC rerun is cancelled **unconditionally**
(`should_rerun_gc = false`, 2344): a cancelled GC coroutine cannot park
again, and a rerun would only spawn an orphan iterator over an
already-reset buffer, a UAF risk (closed by the 2026-07-15 review, test
021_gc_deadlock). If EG(exception) is an instance of
`ZEND_ASYNC_GET_EXCEPTION_CE(ZEND_ASYNC_EXCEPTION_CANCELLATION)`, the
cancellation class the scheduler provides, it is additionally silenced: the
GC coroutine was cancelled (e.g. a terminal deadlock), and that is not a GC
error. Then the GC_DTOR_GARBAGE tags left behind by the unfinished iterator
are cleaned up.

This is the only place in the core that uses `ZEND_ASYNC_GET_EXCEPTION_CE`:
the core needs the cancellation exception class without knowing its name.

## Parked coroutine stacks (the former "theoretical hole", resolved as a non-issue)

References held by the frames of an await-parked coroutine, live
temporaries included, are invisible to the cycle collector. That is safe by
construction, on two grounds:

1. Trial deletion is conservative: it only frees objects whose refcount it
   can fully explain through visible edges. A reference from an invisible
   frame slot leaves the refcount unexplained, so the object is always
   kept; a premature free is impossible.
2. Exposing the frame edges cannot enable any collection either. The
   scheduler's live table anchors every parked coroutine with a reference
   GC cannot see, so the coroutine and everything its frames hold stays
   externally rooted until it finishes; force-close at shutdown unwinds the
   frames and releases the values.

This was verified empirically: a full frame walk from the coroutine's
get_gc (CVs, in-flight call args, live-range temporaries; the helper plus a
WeakReference-instrumented probe) produced zero observable difference in
any scenario, so the walk was not merged. The net effect of invisibility is
at most a deferred collection (until the value is consumed or the request
ends), never a leak across the request and never a use-after-free. Yielded
fiber stacks are different: there the fiber object is not
scheduler-anchored, the walk is observable (gh9735), and it is implemented;
see `zend_fiber_object_gc()` above.

---

## The coroutine context: Zend/zend_async_API.h/.c

Coroutine-local storage is engine machinery, not scheduling policy. Both
stores live on `zend_coroutine_t`, the core owns the layout and the
operations, the engine registers no PHP class, and zend.c carries no
wiring.

**The internal context** (C extensions only, structurally unreachable from
PHP) is a HashTable **embedded by value** in the coroutine. `zend_hash_init`
defers the bucket array to the first insert, so an unused context costs
nothing, and the lazy-pointer allocation is gone. The provider calls
`zend_async_internal_context_init/destroy` at the coroutine's birth and
death. Keys are process-unique numbers allocated once per process from a
static C-string name (`zend_async_internal_context_key_alloc`, typically at
MINIT). The registry is deliberately a process-global static behind a ZTS
mutex, because a key must mean the same thing in every thread and request;
a repeated alloc with the same string address returns the same key.
`zend_async_scheduler_unregister()`, a per-request event for the PHP
bridge, does not touch the registry; only the process shutdown does.

**The userland context** (`Async\Context`): the core defines a plain C
struct, `zend_async_context_t { HashTable string_keys; HashTable
object_keys; zend_object std; }`, plus the operations over it
(`tables_init/destroy`, `entry_find/set/unset/gc`) and the coroutine-level
view (`zend_async_context_get/find/set/unset/destroy`). `std` sits at a
fixed offset inside the base, so `ZEND_ASYNC_CONTEXT_FROM_OBJ` is a
constant `container_of` and an extension may wrap the struct with its own
fields in front. The PHP class and `Async\get_context()` belong to a
provider extension, which hands the core its factory through the
`zend_async_new_context_fn` slot. An object-keyed entry owns the key object
as well as the value: handles are reused once an object dies, and a stored
key that outlived its object would alias whatever takes its handle next.

There is no request-level store: no coroutine, no context. `get_context()`
without a running scheduler is an `Error`. The main coroutine exists from
the launch on, so the script's store is simply the main coroutine's store.

---

## The PHP registration bridge: out of tree

The userland face of the seam (`Async\SchedulerHook::register()`, an
`Async\Scheduler` interface, and the `Async\Context` / `Async\get_context()`
surface over the engine's context storage) used to live in the tree as
`Zend/zend_scheduler_hook.*` plus `ext/async_scheduler_hook`. It has been
extracted to its own repository,
[ext-scheduler-hook](https://github.com/true-async/ext-scheduler-hook), and
the engine now compiles in no PHP symbols at all.

Nothing engine-side was lost in the move: the bridge talked to the core
exclusively through exported `ZEND_API`, which is why it can build as an
ordinary out-of-tree extension. That it still works unchanged is the
runtime proof that the ABI seam is sufficient for a PHP-facing provider,
without the engine owning a single name for it.

## Testing strategy

- test_scheduler is gated by `test_scheduler.enable` (PHP_INI_SYSTEM,
  default **0**): the extension loads but claims no scheduler slots unless
  enabled, so the process-wide slot stays free by default.
- **Upstream core tests are untouched.** Zend/tests/fibers, gc and
  generators carry upstream content byte-for-byte and run schedulerless,
  verifying RFC goal #3: with no scheduler registered, PHP behaves exactly
  as today.
- Tests whose behaviour legitimately differs under a scheduler are
  **duplicated** into ext/test_scheduler/tests (026-059, descriptive names)
  with `--INI-- test_scheduler.enable=1` and the scheduler-adapted EXPECTs.
- 060-067 cover the paths that have no upstream counterpart: spawn failure,
  and the shutdown notification on `exit()`.
- The bridge and context tests moved out with the bridge and now live in
  the [ext-scheduler-hook](https://github.com/true-async/ext-scheduler-hook)
  repository, where they register a scheduler written in plain PHP.

---

## Summary table

| File | Lines | What |
|---|---|---|
| main/main.c | 2259, 2859 | API globals: ctor + slot in the TSRM block |
| main/main.c | 2671 | scheduler launch before the script |
| main/main.c | 2683, 2685 | drain after main (normal / bailout) |
| main/main.c | 2000-2007 | drain at request shutdown + consuming EG |
| main/main.c | 2010, 2583 | deactivation; zeroing the API slots |
| Zend/zend.c | 1986 | deferred uncaught report while the drain is still ahead |
| Zend/zend_globals.h | 172-188 | cursor of the shutdown destructor passes |
| Zend/zend_execute_API.c | 258-337 | shutdown_destructors: switch handler + iterator |
| Zend/zend_objects_API.c | 93-168 | same for the object store + fiber/coroutine skip (active-only) |
| Zend/zend_async_API.h/.c | — | the ABI, the internal context (embedded), the userland context struct + ops |
| ext/test_scheduler | — | the C reference scheduler (test_scheduler.enable, default off) |
| Zend/zend_fibers.h | 147-154 | coroutine-mode fields in zend_fiber |
| Zend/zend_fibers.c | 988-1039 | fiber adoption, start as a coroutine |
| Zend/zend_fibers.c | 882-983 | yield/await via SUSPEND/ENQUEUE |
| Zend/zend_fibers.c | 1346-1450 | Fiber::suspend/resume/throw dispatch |
| Zend/zend_fibers.c | 757-778 | force-closing the coroutine when the Fiber object dies |
| Zend/zend_fibers.c | 848-878 | body finish: error to the caller; unwind exit raises SHUTDOWN |
| Zend/zend_fibers.c | 1156-1173 | GC: a live fiber is a root; the coroutine edge after finish |
| Zend/zend_fibers.c | 553-603 | error_reporting: empty string = E_ALL |
| Zend/zend_gc.c | 2201-2237 | synchronous gc_collect_cycles over the GC coroutine; TMPVAR re-root on both outcomes |
| Zend/zend_gc.c | 2016-2170 | destructor phase: iterators + microtask + finish handler |
| Zend/zend_gc.c | 1885-1932 | destructor suspend detection |
| Zend/zend_gc.c | 2339-2358 | phase outcome: no rerun, silencing the cancellation, tag cleanup |
