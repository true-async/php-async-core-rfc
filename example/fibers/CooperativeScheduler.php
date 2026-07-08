<?php

/**
 * Cooperative fiber scheduler on the Async Scheduler Hook API.
 *
 * It implements Async\Scheduler directly. The onSuspend() hook returns the
 * coroutine it switched to, which the engine records as the current coroutine.
 *
 * How a coroutine sees *itself* is the scheduler's own business (the core does
 * not expose that inside a running coroutine): this scheduler keeps a
 * Fiber -> coroutine map and offers currentCoroutine() built on it.
 *
 *     Async\SchedulerHook::register('cooperative', new CooperativeScheduler());
 */
final class CooperativeScheduler implements \Async\Scheduler
{
    /** The active instance, so the free helpers below can reach it. */
    public static CooperativeScheduler $instance;

    /** Coroutines ready to (re)run. */
    private \SplQueue $ready;

    /** One-shot microtasks (onDefer). */
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

    /**
     * The scheduler's own re-queue API, called by the park()/await() helpers.
     * This is a plain method, NOT a hook: the engine never calls it.
     */
    public function resume(object $coroutine): void
    {
        $this->ready->enqueue($coroutine);
    }

    // ------------------------------------------------------------------
    // Async\Scheduler hooks
    // ------------------------------------------------------------------

    /** This scheduler drives fibers, so it uses none of the mandate. */
    public function onLaunch(
        \Closure $createContinuation,
        \Closure $currentContinuation,
        \Closure $currentCoroutine,
    ): ?object {
        return null;
    }

    public function onShutdown(): bool
    {
        return true;
    }

    /** A fiber is starting: adopt it as a coroutine handle and remember it. */
    public function onFiber(\Fiber $fiber): ?object
    {
        $coroutine = new class ($fiber) {
            public function __construct(public readonly \Fiber $fiber) {}
        };

        $this->byFiber[$fiber] = $coroutine;
        return $coroutine;
    }

    /**
     * Make a coroutine runnable: queue it. Enqueuing a fresh coroutine and
     * resuming a suspended one are the same operation here.
     */
    public function onEnqueue(object $coroutine, ?\Throwable $error = null): bool
    {
        $this->ready->enqueue($coroutine);
        return true;
    }

    /** A microtask (used e.g. to drive a concurrent iterator): store it. */
    public function onDefer(callable $task): bool
    {
        $this->microtasks->enqueue($task);
        return true;
    }

    /**
     * The current flow yields. While the main script runs we only collect
     * coroutines; the engine hands us control at the end (onSuspend with
     * $fromMain). We run everyone and return the coroutine we last switched to,
     * which the engine records as the current one.
     */
    public function onSuspend(bool $fromMain, bool $isBailout): ?object
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

    // Coroutine context: this example does not use it.
    public function getContext(object $coroutine): object { return new \stdClass(); }
    public function getInternalContext(object $coroutine): object { return new \stdClass(); }
    public function contextFind(object $context, mixed $key): mixed { return null; }
    public function contextSet(object $context, mixed $key, mixed $value): bool { return true; }
    public function contextUnset(object $context, mixed $key): bool { return true; }
}

// ----------------------------------------------------------------------
// User-facing helpers, kept apart from the scheduler hooks. `park()`/`await()`
// are what application code calls; the onSuspend() hook is the engine contract.
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
