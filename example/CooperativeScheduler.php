<?php

/**
 * Cooperative fiber scheduler — a self-contained module built on the Async
 * Scheduler Hook API.
 *
 * Include the file and it registers the scheduler (switching PHP into
 * concurrent mode); then drive coroutines with Cooperative\spawn() and
 * Cooperative\suspend(). There is no class and no setup call: the hooks are
 * plain closures over two queues.
 */

namespace Cooperative;

use Fiber;
use SplQueue;
use Throwable;

/** Start a coroutine — the pure-PHP twin of Async\spawn(callable $task, ...$args). */
function spawn(callable $task, mixed ...$args): void
{
    new Fiber($task)->start(...$args);
}

/** Cooperative yield — the pure-PHP twin of Async\suspend(). */
function suspend(): void
{
    Fiber::suspend();
}

/*
 * Register the scheduler. Runs once, when this file is included. Everything it
 * needs is captured by `use`, so nothing leaks into the including script.
 */
(static function (): void {
    $ready      = new SplQueue();  // coroutines ready to (re)run
    $microtasks = new SplQueue();  // one-shot callbacks (Async\SchedulerHook::defer)

    \Async\SchedulerHook::register('cooperative', [

        // A fiber is starting: adopt it as a coroutine handle.
        \Async\SchedulerHook::INTERCEPT_FIBER =>
            fn (Fiber $fiber): object => new class ($fiber) {
                public function __construct(public readonly Fiber $fiber) {}
            },

        // A coroutine is ready (just created, or its wait finished): queue it.
        \Async\SchedulerHook::ENQUEUE =>
            function (object $coroutine) use ($ready): bool {
                $ready->enqueue($coroutine);
                return true;
            },
        \Async\SchedulerHook::RESUME =>
            function (object $coroutine, ?Throwable $error) use ($ready): bool {
                $ready->enqueue($coroutine);
                return true;
            },

        // A microtask (used e.g. to drive a concurrent iterator): store it.
        \Async\SchedulerHook::DEFER =>
            function (callable $task) use ($microtasks): bool {
                $microtasks->enqueue($task);
                return true;
            },

        // The current flow yields. While the main script runs we only collect
        // coroutines; the engine hands us control for real when it ends.
        \Async\SchedulerHook::SUSPEND =>
            function (bool $fromMain, bool $isBailout) use ($ready, $microtasks): bool {

                if (!$fromMain) {
                    return true;   // still collecting: run everyone later
                }

                if ($isBailout) {
                    // Main ended abnormally (exit(), fatal error). Policy: abandon
                    // the queued coroutines — we simply never run them.
                    return true;
                }

                // Normal end of script: run everyone to completion, fairly.
                while (!$microtasks->isEmpty() || !$ready->isEmpty()) {

                    while (!$microtasks->isEmpty()) {
                        ($microtasks->dequeue())();
                    }
                    if ($ready->isEmpty()) {
                        break;
                    }

                    $coroutine = $ready->dequeue();
                    $fiber     = $coroutine->fiber;

                    // The real context switch: inside scheduler code start() /
                    // resume() switch directly instead of re-entering the hooks.
                    $fiber->isStarted() ? $fiber->resume() : $fiber->start();

                    if ($fiber->isSuspended()) {
                        $ready->enqueue($coroutine);   // more work: another turn
                    }
                }

                return true;
            },
    ]);
})();
