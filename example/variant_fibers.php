<?php

/**
 * VARIANT A — cooperative scheduler on plain Fibers (asymmetric).
 *
 *   php example/variant_fibers.php
 *
 * Coroutines ARE Fibers. The scheduler drives them with $fiber->resume(), and a
 * coroutine yields with Fiber::suspend(), which returns to the resumer. So every
 * switch goes  coroutine -> scheduler (in {main}) -> coroutine : a central pump,
 * never a direct coroutine-to-coroutine jump. This runs on today's engine.
 */

final class FiberScheduler extends \Async\AbstractScheduler
{
    public static FiberScheduler $instance;
    private \SplQueue $ready;
    private \SplObjectStorage $byFiber;   // Fiber -> coroutine handle (self-lookup)

    public function __construct()
    {
        self::$instance = $this;
        $this->ready    = new \SplQueue();
        $this->byFiber  = new \SplObjectStorage();
    }

    public function coroutineFor(\Fiber $fiber): object
    {
        return $this->byFiber[$fiber];
    }

    /** A fiber starts: adopt it as a coroutine handle. */
    public function interceptFiber(\Fiber $fiber): ?object
    {
        $coroutine = new class ($fiber) {
            public function __construct(public readonly \Fiber $fiber) {}
        };

        $this->byFiber[$fiber] = $coroutine;
        return $coroutine;
    }

    public function enqueue(object $coroutine): bool
    {
        $this->ready->enqueue($coroutine);
        return true;
    }

    public function resume(object $coroutine, ?\Throwable $error = null): bool
    {
        $this->ready->enqueue($coroutine);   // deferred wake
        return true;
    }

    /** The pump: drain the queue by RESUMING each fiber. Asymmetric. */
    public function suspend(bool $fromMain, bool $isBailout): ?object
    {
        if (!$fromMain || $isBailout) {
            return null;
        }

        $current = null;

        while (!$this->ready->isEmpty()) {
            $current = $this->ready->dequeue();
            $fiber   = $current->fiber;

            // <-- the switch: control enters the fiber and RETURNS here when it
            //     yields. coroutine -> pump -> coroutine.
            if (!$fiber->isStarted()) {
                $fiber->start();
            } elseif ($fiber->isSuspended()) {
                $fiber->resume();
            }
        }

        return $current;
    }
}

// --- user-facing helpers ---------------------------------------------------

function spawn(callable $task): void
{
    new Fiber($task)->start();
}

function currentCoroutine(): object
{
    return FiberScheduler::$instance->coroutineFor(Fiber::getCurrent());
}

/** Yield: reschedule self, then suspend back to the pump. */
function yield_(): void
{
    Async\Coroutine::resume(currentCoroutine());
    Fiber::suspend();
}

// --- demo ------------------------------------------------------------------

Async\SchedulerHook::register('fibers', new FiberScheduler());

function worker(string $name, int $steps): void
{
    for ($i = 1; $i <= $steps; $i++) {
        echo "    [$name] step $i\n";
        yield_();
    }
}

echo "main: spawn A, B (fibers)\n";
spawn(fn () => worker('A', 3));
spawn(fn () => worker('B', 3));
echo "main: end — pump drains, coroutines interleave via {main}\n";
