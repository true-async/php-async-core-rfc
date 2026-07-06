<?php

/**
 * Full walkthrough of the Async Scheduler Hook API on pure PHP.
 *
 * Run it:  php example/run.php   (needs a PHP built with the async core)
 *
 * It proves three things:
 *   1. Independent coroutines run concurrently and interleave.
 *   2. The switch is real cooperative multitasking, driven by Fiber.
 *   3. The main flow itself can hand control to the coroutines.
 */

require __DIR__ . '/CooperativeScheduler.php';

// Activate concurrency. Every fiber started from now on is scheduled.
(new CooperativeScheduler())->activate();

/**
 * A coroutine body: it does `$steps` units of work and cooperatively
 * yields after each one, letting other coroutines advance.
 */
function worker(string $name, int $steps): void
{
    for ($step = 1; $step <= $steps; $step++) {
        echo "    [$name] step $step of $steps\n";
        yieldToScheduler();
    }
    echo "    [$name] finished\n";
}

/** Cooperative yield: pause here and let the scheduler run someone else. */
function yieldToScheduler(): void
{
    Fiber::suspend();
}

/** Start a coroutine. The scheduler adopts and queues the new fiber. */
function spawn(callable $body, mixed ...$args): void
{
    (new Fiber($body))->start(...$args);
}

// --------------------------------------------------------------------
echo "main: starting, about to spawn two coroutines\n";

spawn(worker(...), 'A', 3);
spawn(worker(...), 'B', 2);

echo "main: both coroutines are queued but not finished yet\n";

// A microtask (used by things like a concurrent iterator) runs on the
// next scheduler tick, before/around the coroutines drain.
Async\SchedulerHook::defer(function (): void {
    echo "    [microtask] ran on the scheduler tick\n";
});

echo "main: reaching the end of the script\n";

// When the script ends, the engine hands control to the scheduler one
// last time (SUSPEND with fromMain = true). That is where A and B run to
// completion, interleaving step by step. Watch the order below.
echo "main: --- handover to scheduler ---\n";
