<?php

/**
 * Coroutines run concurrently and interleave.
 *
 *   php example/interleaving.php
 *
 * Two coroutines each do a few steps, yielding after every step. The `A, B, A,
 * B, ...` order in the output is the proof that control switches between the
 * two fibers — and that the main flow hands control to the scheduler when it
 * ends.
 */

require __DIR__ . '/CooperativeScheduler.php';

// Activate concurrency by registering a scheduler instance.
Async\SchedulerHook::register('cooperative', fn () => new CooperativeScheduler());

function worker(string $name, int $steps): void
{
    for ($step = 1; $step <= $steps; $step++) {
        echo "    [$name] step $step of $steps\n";
        park();   // let another coroutine advance
    }

    echo "    [$name] finished\n";
}

echo "main: spawning two coroutines\n";

spawn(worker(...), 'A', 3);
spawn(worker(...), 'B', 2);

echo "main: end of script — handing control to the scheduler\n";
// The queued coroutines now run, interleaved step by step.
