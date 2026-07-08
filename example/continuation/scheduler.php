<?php

/**
 * VARIANT B — cooperative scheduler on Continuations (symmetric).
 *
 * Coroutines ARE Continuations (minted by the scheduler through the
 * createContinuation mandate, no Fiber). The scheduler switches directly into a
 * coroutine with $coroutine->switchTo(): a symmetric jump, no central hub.
 *
 * The mandate (createContinuation + currentCoroutine) arrives only in onLaunch(),
 * so nobody but the scheduler can mint coroutines. Switching is a method on the
 * Continuation the scheduler holds.
 *
 * Runs on the engine's Async\Continuation.
 */

final class ContinuationScheduler implements \Async\Scheduler
{
    public static ContinuationScheduler $instance;
    private \SplQueue $ready;

    /** The mandate — held only by the scheduler. */
    private \Closure $createContinuation;  // createContinuation(callable $entry): Continuation
    private \Closure $currentCoroutine;    // currentCoroutine(): ?object

    public function __construct()
    {
        self::$instance = $this;
        $this->ready    = new \SplQueue();
    }

    /** The engine hands the mandate once, at start. */
    public function onLaunch(\Closure $createContinuation, \Closure $currentCoroutine): ?object
    {
        $this->createContinuation = $createContinuation;
        $this->currentCoroutine   = $currentCoroutine;
        return null;
    }

    /** Spawn: the scheduler mints its OWN coroutine as a Continuation. */
    public function spawn(callable $task): void
    {
        $this->ready->enqueue(($this->createContinuation)($task));
    }

    /**
     * End of main: the engine hands us control (onSuspend with $fromMain). We
     * switch into each ready coroutine in turn with switchTo() — a direct
     * symmetric jump into the Continuation, no intermediary.
     */
    public function onSuspend(bool $fromMain, bool $isBailout): ?object
    {
        if (!$fromMain || $isBailout) {
            return null;
        }

        while (!$this->ready->isEmpty()) {
            $this->ready->dequeue()->switchTo();
        }

        return null;
    }

    public function onEnqueue(object $coroutine, ?\Throwable $error = null): bool
    {
        $this->ready->enqueue($coroutine);
        return true;
    }

    // This scheduler runs only its own Continuations, so it adopts no foreign
    // fibers (a Revolt/AMPHP-hosting scheduler would return a coroutine here).
    public function onFiber(\Fiber $fiber): ?object
    {
        return null;
    }

    public function onShutdown(): bool { return true; }
    public function onDefer(callable $task): bool { return false; }

    public function getContext(object $coroutine): object { return new \stdClass(); }
    public function getInternalContext(object $coroutine): object { return new \stdClass(); }
    public function contextFind(object $context, mixed $key): mixed { return null; }
    public function contextSet(object $context, mixed $key, mixed $value): bool { return false; }
    public function contextUnset(object $context, mixed $key): bool { return false; }
}

// --- user-facing helper ----------------------------------------------------

function spawn(callable $task): void
{
    ContinuationScheduler::$instance->spawn($task);
}

// --- demo ------------------------------------------------------------------

\Async\SchedulerHook::register('continuation', new ContinuationScheduler());

function worker(string $name, int $steps): void
{
    for ($i = 1; $i <= $steps; $i++) {
        echo "    [$name] step $i\n";
    }
}

echo "main: spawn A, B (continuations)\n";
spawn(fn () => worker('A', 3));
spawn(fn () => worker('B', 2));
echo "main: end — scheduler switches directly into each continuation\n";
