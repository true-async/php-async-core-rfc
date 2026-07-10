# Plan: internal context moves into the coroutine structure

Status: agreed 2026-07-09; this file tracks the change until it lands everywhere.

## Motivation

The context is a hot path. Without coroutine-local memory nothing works on top of the
scheduler: not the frameworks (request id, DI scopes, transaction state), not the core
subsystems that keep per-flow state in what used to be globals (output buffering resolves its
handler stack on every byte printed; `gethostbyname()` keeps a static result buffer). Routing
every such access through scheduler hooks costs a slot call at best and a PHP method call at
worst, on an operation that fires orders of magnitude more often than a context switch.

The hook design also gave the scheduler a job it has no opinion about: context storage is
mechanism, not scheduling policy. Five of the ten interface methods were context plumbing.

## Design

1. **Internal context lives in `zend_coroutine_t`.** A lazily allocated numeric-keyed
   `HashTable *internal_context` field. The engine owns the storage; C extensions reach it
   through the existing macros (`ZEND_ASYNC_INTERNAL_CONTEXT_FIND/SET/UNSET`), which now take
   the coroutine (NULL = current) and resolve to engine functions, not scheduler slots. The
   worked examples (output buffering, `gethostbyname()`) stay valid verbatim.
2. **Userland context leaves the contract entirely.** Its consumers live in PHP; a scheduler
   implements it in its own coroutine class without engine involvement. It joins `spawn()` /
   `await()` in the out-of-scope, user-facing API layer.
3. **The `Scheduler` interface shrinks to events only:** onShutdown, onFiber, onEnqueue,
   onSuspend, onDefer, onWaitInfo.
4. **`ZEND_ASYNC_CURRENT_COROUTINE` must be correct for every flavour of coroutine,
   fibers included.** A bound fiber carries its coroutine pointer (`fiber->coroutine`): the
   engine sets the current coroutine when it switches into the fiber and restores it on the
   way out. For continuation-backed coroutines (a PHP scheduler's own), the bridge mints a
   lightweight `zend_coroutine_t` handle when the object is first recorded as current.

## Steps

- [ ] Core: `internal_context` field + engine-owned find/set/unset/destroy, macros retargeted.
- [ ] Core: drop `zend_async_context_t`, the context slots, typedefs, externs and macros
      (deliberate ABI break).
- [ ] Fibers: set/restore `ZEND_ASYNC_G(coroutine)` around bound-fiber execution.
- [ ] Bridge: coroutine-object -> handle registry for record-current; delete the context
      thunks and registries; destroy the internal context in coroutine dispose.
- [ ] Stub/tests: remove the five context methods from `Async\Scheduler`.
- [ ] RFC: motivation (hot path), interface listing, context sections rewritten, MiniScheduler,
      changelog 0.3. SCHEDULER.md: error-channel note.

## Known gaps (PoC level)

- ~~Continuation-backed coroutine handles (and their internal context) are released at
  unregistration, not at coroutine finish.~~ Resolved: the context must not outlive the
  coroutine, so the bridge installs a C destructor on the coroutine object itself (a
  per-class copy of the object handlers with free_obj wrapped); the handle and its internal
  context are destroyed the moment the object dies. Fiber-adopted handles keep their stronger
  lifetime: the fiber machinery holds a raw pointer, so the handle pins the object and the
  fiber teardown drives the release.
- Raw-pointer values stored in the internal context are freed by their owning extension; the
  engine only drops the zval slots.
