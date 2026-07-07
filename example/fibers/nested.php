<?php

/**
 * Nested coroutines just work.
 *
 *   php example/nested.php
 *
 * A coroutine spawns another coroutine from inside its own body. It works
 * because the scheduler never switches fiber-to-fiber directly: every coroutine
 * yields back to the scheduler (running in the main flow) before the next one is
 * resumed, so no coroutine is ever resumed while it is still on the stack.
 * See fiber_switch_limit.php for what that rule protects us from.
 */

require __DIR__ . '/CooperativeScheduler.php';

Async\SchedulerHook::register('cooperative', new CooperativeScheduler());

function child(string $name): void
{
    echo "    child $name: step 1\n";
    park();

    echo "    child $name: step 2\n";
}

function parentTask(): void
{
    echo "  parent: start\n";
    park();

    echo "  parent: spawning a child coroutine from inside a coroutine\n";
    spawn(child(...), 'C');
    park();

    echo "  parent: after the child, another step\n";
    park();

    echo "  parent: done\n";
}

echo "main: spawning the parent coroutine\n";

spawn(parentTask(...));

echo "main: end of script\n";
