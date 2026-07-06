<?php

/**
 * Proof that control really switches between the MAIN flow and a coroutine.
 *
 * Run it:  php example/switch_from_main.php
 *
 * This scheduler performs exactly ONE switch per hand-off, so the main
 * script can step a coroutine by hand and watch control bounce back and
 * forth, with values passed in both directions.
 */

$readyCoroutines = new SplQueue();

Async\SchedulerHook::register('step-by-step', [
    // Adopt a starting fiber as a coroutine handle.
    Async\SchedulerHook::INTERCEPT_FIBER => fn (Fiber $fiber): object
        => new class ($fiber) {
            public function __construct(public readonly Fiber $fiber) {}
        },

    // A coroutine is ready to (re)run.
    Async\SchedulerHook::ENQUEUE => function (object $coroutine) use ($readyCoroutines): bool {
        $readyCoroutines->enqueue($coroutine);
        return true;
    },
    Async\SchedulerHook::RESUME => function (object $coroutine, ?Throwable $error) use ($readyCoroutines): bool {
        $readyCoroutines->enqueue($coroutine);
        return true;
    },

    // The main flow yields: run a single ready coroutine, then hand control
    // straight back to main. The switch is the plain Fiber start()/resume().
    Async\SchedulerHook::SUSPEND => function (bool $fromMain, bool $isBailout) use ($readyCoroutines): bool {
        if ($readyCoroutines->isEmpty()) {
            return true;
        }
        $fiber = $readyCoroutines->dequeue()->fiber;
        $fiber->isStarted() ? $fiber->resume() : $fiber->start();
        return true;
    },
]);

// A coroutine that counts down. After each number it yields the number back
// to the main flow and waits to be told the next one.
$countdown = new Fiber(function (int $from): string {
    while ($from > 0) {
        echo "    [coroutine] counting $from\n";
        $from = Fiber::suspend($from);   // hand the number to main, pause
    }
    return 'lift-off';
});

echo "main: switch INTO the coroutine\n";
$got = $countdown->start(3);
echo "main: control is back; coroutine handed me $got\n\n";

echo "main: switch back into the coroutine\n";
$got = $countdown->resume(2);
echo "main: control is back; coroutine handed me $got\n\n";

echo "main: switch back into the coroutine\n";
$got = $countdown->resume(1);
echo "main: control is back; coroutine handed me $got\n\n";

echo "main: one more switch; this time the coroutine returns\n";
$countdown->resume(0);
echo "main: coroutine finished with: ", $countdown->getReturn(), "\n";
