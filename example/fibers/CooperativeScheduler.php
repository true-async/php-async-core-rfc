<?php

/**
 * Cooperative fiber scheduler on the Async Scheduler Hook API.
 *
 * It implements Async\Scheduler by extending Async\AbstractScheduler. The
 * `suspend()` hook returns the coroutine it switched to, which the engine
 * records as the current coroutine.
 *
 * How a coroutine sees *itself* is the scheduler's own business (the core does
 * not expose that inside a running coroutine): this scheduler keeps a
 * Fiber -> coroutine map and offers currentCoroutine() built on it.
 *
 *     Async\SchedulerHook::register('cooperative', new CooperativeScheduler());
 */
final class CooperativeScheduler extends \Async\AbstractScheduler
{
    /** The active instance, so the free helpers below can reach it. */
    public static CooperativeScheduler $instance;

    /** Coroutines ready to (re)run. */
    private \SplQueue $ready;

    /** One-shot microtasks (defer). */
    private \SplQueue $microtasks;

    /** Fiber -> coroutine handle, so a running coroutine can find itself. */
    private \SplObjectStorage $byFiber;

    public function __construct()
    {
        self::$instance   = $this;
        $this->ready      = new \SplQueue();
        $this->microtasks = new \SplQueue();
        $this->byFiber    = new \SplObjectStorage();
    }

    /** The coroutine handle bound to a fiber (used by currentCoroutine()). */
    public function coroutineFor(\Fiber $fiber): object
    {
        return $this->byFiber[$fiber];
    }

    // ------------------------------------------------------------------
    // Async\Scheduler hooks
    // ------------------------------------------------------------------

    /** A fiber is starting: adopt it as a coroutine handle and remember it. */
    public function interceptFiber(\Fiber $fiber): ?object
    {
        $coroutine = new class ($fiber) {
            public function __construct(public readonly \Fiber $fiber) {}
        };

        $this->byFiber[$fiber] = $coroutine;
        return $coroutine;
    }

    /** A coroutine is ready (created, or its wait finished): queue it. */
    public function enqueue(object $coroutine): bool
    {
        $this->ready->enqueue($coroutine);
        return true;
    }

    /** Deferred wake: put the coroutine back on the run queue. */
    public function resume(object $coroutine, ?\Throwable $error = null): bool
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
     * coroutines; the engine hands us control at the end. We run everyone and
     * return the coroutine we last switched to — the engine records it as the
     * current one.
     */
    public function suspend(bool $fromMain, bool $isBailout): ?object
    {
        if (!$fromMain || $isBailout) {
            return null;
        }

        $current = null;

        while (!$this->microtasks->isEmpty() || !$this->ready->isEmpty()) {
            while (!$this->microtasks->isEmpty()) {
                ($this->microtasks->dequeue())();
            }

            if ($this->ready->isEmpty()) {
                break;
            }

            $current = $this->ready->dequeue();
            $fiber   = $current->fiber;

            if (!$fiber->isStarted()) {
                $fiber->start();
            } elseif ($fiber->isSuspended()) {
                $fiber->resume();
            }
        }

        return $current;
    }
}

// ----------------------------------------------------------------------
// User-facing helpers, kept apart from the scheduler hooks. `park()`/`await()`
// are what application code calls; the suspend() hook is the engine contract.
// ----------------------------------------------------------------------

/** Start a coroutine. */
function spawn(callable $task, mixed ...$args): void
{
    new Fiber($task)->start(...$args);
}

/** The coroutine handle of the running fiber (the scheduler's own lookup). */
function currentCoroutine(): object
{
    return CooperativeScheduler::$instance->coroutineFor(Fiber::getCurrent());
}

/** Cooperative yield: reschedule the current coroutine, then let others run. */
function park(): void
{
    CooperativeScheduler::$instance->resume(currentCoroutine());
    Fiber::suspend();
}

/** Await: suspend until the scheduler resumes us. */
function await(): void
{
    Fiber::suspend();
}
