<?php

/**
 * Cooperative fiber scheduler — a self-contained scheduler built on the Async
 * Scheduler Hook API. It implements Async\Scheduler by extending
 * Async\AbstractScheduler, so only the hooks it needs are overridden; state
 * lives in ordinary properties (no captured closures).
 *
 * Register an instance and drive coroutines with spawn() / suspend():
 *
 *     Async\SchedulerHook::register('cooperative', new CooperativeScheduler());
 */
final class CooperativeScheduler extends \Async\AbstractScheduler
{
    /** Coroutines ready to (re)run, in round-robin order. */
    private \SplQueue $ready;

    /** One-shot callbacks queued via defer() (microtasks). */
    private \SplQueue $microtasks;

    public function __construct()
    {
        $this->ready      = new \SplQueue();
        $this->microtasks = new \SplQueue();
    }

    // ------------------------------------------------------------------
    // Async\Scheduler hooks
    // ------------------------------------------------------------------

    /** A fiber is starting: adopt it as a coroutine handle. */
    public function interceptFiber(\Fiber $fiber): ?object
    {
        return new class ($fiber) {
            public function __construct(public readonly \Fiber $fiber) {}
        };
    }

    /** A coroutine is ready (created, or its wait finished): queue it. */
    public function enqueue(object $coroutine): bool
    {
        $this->ready->enqueue($coroutine);
        return true;
    }

    /** A microtask (used e.g. to drive a concurrent iterator): store it. */
    public function defer(callable $task): bool
    {
        $this->microtasks->enqueue($task);
        return true;
    }

    /**
     * The current flow yields. While the main script runs we only collect
     * coroutines; the engine hands us control for real when it ends.
     */
    public function suspend(bool $fromMain, bool $isBailout): bool
    {
        if (!$fromMain) {
            return true;   // still collecting: run everyone later
        }

        if ($isBailout) {
            return true;   // main ended abnormally: abandon the queued coroutines
        }

        while (!$this->microtasks->isEmpty() || !$this->ready->isEmpty()) {
            while (!$this->microtasks->isEmpty()) {
                ($this->microtasks->dequeue())();
            }

            if ($this->ready->isEmpty()) {
                break;
            }

            $coroutine = $this->ready->dequeue();
            $fiber     = $coroutine->fiber;

            // The real context switch: inside scheduler code start() / resume()
            // switch directly instead of re-entering the hooks.
            $fiber->isStarted() ? $fiber->resume() : $fiber->start();

            if ($fiber->isSuspended()) {
                $this->ready->enqueue($coroutine);   // more work: another turn
            }
        }

        return true;
    }
}

// ----------------------------------------------------------------------
// User-facing helpers. Kept deliberately separate from the scheduler's
// hooks: `suspend()` above is the engine contract; the helpers below are
// what application code calls. `park()` is named apart from the suspend
// hook on purpose, to keep the two roles from blurring.
// ----------------------------------------------------------------------

/** Start a coroutine — the example's twin of Async\spawn(). */
function spawn(callable $task, mixed ...$args): void
{
    new Fiber($task)->start(...$args);
}

/** Cooperative yield: pause the current coroutine, let the scheduler run someone else. */
function park(): void
{
    Fiber::suspend();
}
