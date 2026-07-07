<?php

/**
 * VARIANT B — cooperative scheduler on Continuations (symmetric).
 *
 * ILLUSTRATIVE: this uses the *proposed* API — Async\Continuation plus the
 * switchTo / createCoroutine mandate handed to onLaunch(). Those engine
 * primitives do not exist yet, so this file documents the design; it is not
 * runnable until the C side lands.
 *
 * Coroutines ARE Continuations (created by the scheduler, no Fiber). The
 * scheduler switches directly A -> B with switchTo(): a symmetric jump, no
 * central pump, no {main} round-trip. Switching is a privilege: switchTo /
 * createCoroutine arrive only in onLaunch(), so nobody but the scheduler can
 * switch contexts.
 */

final class ContinuationScheduler implements \Async\Scheduler
{
    public static ContinuationScheduler $instance;
    private \SplQueue $ready;

    /** The secret mandate — held only by the scheduler. */
    private \Closure $switchTo;         // switchTo(Continuation $to, mixed $value = null): mixed
    private \Closure $createCoroutine;  // createCoroutine(callable $entry): Continuation
    private \Closure $currentCoroutine; // currentCoroutine(): ?Continuation

    public function __construct()
    {
        self::$instance = $this;
        $this->ready    = new \SplQueue();
    }

    /** The engine hands the privileged functions once, at start. */
    public function onLaunch(callable $switchTo, callable $createCoroutine, callable $currentCoroutine): void
    {
        $this->switchTo         = $switchTo;
        $this->createCoroutine  = $createCoroutine;
        $this->currentCoroutine = $currentCoroutine;
    }

    /** Spawn: the scheduler mints its OWN coroutine as a Continuation. */
    public function spawn(callable $task): void
    {
        $this->ready->enqueue(($this->createCoroutine)($task));
    }

    /** A coroutine yielded. Reschedule it, then jump DIRECTLY to the next. */
    public function onSuspend(\Async\Continuation $current): void
    {
        $this->ready->enqueue($current);

        // <-- the switch: a direct symmetric jump A -> B. No pump, no {main}.
        //     The engine records the target as the current coroutine.
        ($this->switchTo)($this->ready->dequeue());
    }

    public function onEnqueue(\Async\Continuation $coroutine): bool
    {
        $this->ready->enqueue($coroutine);
        return true;
    }

    public function onResume(\Async\Continuation $coroutine, ?\Throwable $error = null): bool
    {
        $this->ready->enqueue($coroutine);   // deferred wake
        return true;
    }

    public function onCancel(\Async\Continuation $coroutine, ?\Throwable $error = null): bool
    {
        return true;
    }

    // This scheduler runs only its own Continuations, so it adopts no foreign
    // fibers (a Revolt/AMPHP-hosting scheduler would return a Continuation here).
    public function onFiber(\Fiber $fiber): ?\Async\Continuation
    {
        return null;
    }

    public function onShutdown(): bool { return true; }
    public function onDefer(callable $task): bool { return false; }

    public function getContext(\Async\Continuation $coroutine): ?object { return null; }
    public function getInternalContext(\Async\Continuation $coroutine): ?object { return null; }
    public function contextFind(object $context, mixed $key, bool $includeParent): mixed { return null; }
    public function contextSet(object $context, mixed $key, mixed $value): bool { return false; }
    public function contextUnset(object $context, mixed $key): bool { return false; }
}

// --- user-facing helpers ---------------------------------------------------

function spawn(callable $task): void
{
    ContinuationScheduler::$instance->spawn($task);
}

/** Yield: the engine calls onSuspend(), which switches straight to the next. */
function yield_(): void
{
    // A userland yield still routes through the scheduler's onSuspend hook;
    // the scheduler does the direct switchTo. No self-reschedule dance needed.
    \Async\suspend();   // (illustrative primitive)
}

// --- demo (illustrative — does not run yet) --------------------------------

\Async\SchedulerHook::register('continuation', new ContinuationScheduler());

function worker(string $name, int $steps): void
{
    for ($i = 1; $i <= $steps; $i++) {
        echo "    [$name] step $i\n";
        yield_();
    }
}

echo "main: spawn A, B (continuations)\n";
spawn(fn () => worker('A', 3));
spawn(fn () => worker('B', 3));
echo "main: end — coroutines switch directly A<->B, no pump\n";
