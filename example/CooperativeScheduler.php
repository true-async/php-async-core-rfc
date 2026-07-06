<?php

/**
 * A complete, minimal scheduler written in pure PHP on top of the
 * Async Scheduler Hook API. It drives plain `Fiber` objects: the engine
 * hands every started fiber to this scheduler, and the scheduler decides
 * the order in which they run.
 *
 * The whole driver is four hooks plus a run queue. Every hook has a
 * speaking name so the mapping "engine event -> scheduler policy" is
 * obvious.
 */
final class CooperativeScheduler
{
    /** Coroutines that are ready to (re)run, in round-robin order. */
    private SplQueue $readyCoroutines;

    /** One-shot callbacks queued via SchedulerHook::defer() (microtasks). */
    private SplQueue $microtasks;

    /**
     * Creating the scheduler turns PHP into concurrent mode: the constructor
     * registers the hooks, so from this point on every fiber the program
     * starts is routed through them. The engine keeps the hook closures (they
     * are bound to $this), so the object stays alive on its own.
     */
    public function __construct()
    {
        $this->readyCoroutines = new SplQueue();
        $this->microtasks      = new SplQueue();

        Async\SchedulerHook::register('cooperative-demo', [
            // A fiber is starting: adopt it as a coroutine of this scheduler.
            Async\SchedulerHook::INTERCEPT_FIBER => $this->adoptFiber(...),

            // A coroutine became runnable (freshly created, or its wait ended).
            Async\SchedulerHook::ENQUEUE => $this->markReady(...),
            Async\SchedulerHook::RESUME  => $this->markReady(...),

            // The current flow yields: pick who runs next and switch to them.
            Async\SchedulerHook::SUSPEND => $this->runUntilAllIdle(...),

            // Microtasks: one-shot callbacks. Needed, for example, to drive a
            // concurrent iterator. The queue belongs to the scheduler.
            Async\SchedulerHook::DEFER => $this->queueMicrotask(...),
        ]);
    }

    // ------------------------------------------------------------------
    // Hooks
    // ------------------------------------------------------------------

    /** INTERCEPT_FIBER: wrap the starting fiber into a coroutine handle. */
    private function adoptFiber(Fiber $fiber): object
    {
        return new class ($fiber) {
            public function __construct(public readonly Fiber $fiber) {}
        };
    }

    /** ENQUEUE / RESUME: the coroutine is ready, put it at the tail. */
    private function markReady(object $coroutine, ?Throwable $error = null): bool
    {
        $this->readyCoroutines->enqueue($coroutine);
        return true;
    }

    /** DEFER: store a microtask; it runs on the next drain. */
    private function queueMicrotask(callable $task): bool
    {
        $this->microtasks->enqueue($task);
        return true;
    }

    /**
     * SUSPEND: the heart of the scheduler. The flow that just yielded (a
     * coroutine, or the main script at the end of the request) gives us
     * control; we keep switching into ready coroutines until none remain.
     *
     * The actual context switch is a plain Fiber call: start() or resume().
     * Inside scheduler code these perform the *direct* switch instead of
     * routing back through the hooks, so there is no recursion.
     */
    private function runUntilAllIdle(bool $fromMain, bool $isBailout): bool
    {
        // While the main script is still running we only collect coroutines,
        // so that several of them are queued together and can interleave. The
        // engine gives us control for real at the end of the script, with
        // $fromMain = true, and that is when we run everything.
        if (!$fromMain) {
            return true;
        }

        do {
            // Microtasks first: drain the whole batch before coroutines.
            while (!$this->microtasks->isEmpty()) {
                ($this->microtasks->dequeue())();
            }

            if ($this->readyCoroutines->isEmpty()) {
                break;
            }

            $coroutine = $this->readyCoroutines->dequeue();
            $fiber     = $coroutine->fiber;

            // The real switch: control jumps into the coroutine here and
            // comes back when it yields (Fiber::suspend) or returns.
            $fiber->isStarted() ? $fiber->resume() : $fiber->start();

            // It yielded with more work to do: schedule it for another turn.
            if ($fiber->isSuspended()) {
                $this->readyCoroutines->enqueue($coroutine);
            }
        } while (true);

        return true;
    }
}
