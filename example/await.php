<?php

/**
 * Awaiting a value across coroutines — the correct way.
 *
 *   php example/await.php
 *
 * A coroutine takes a handle to itself with Async\Coroutine::current(), then
 * suspends. Another coroutine wakes it with Async\Coroutine::resume() — a
 * *deferred* wake that hands the coroutine back to the scheduler to be run
 * later, never an immediate `$fiber->resume()`. That is what keeps a fiber from
 * being resumed while it is still on another fiber's stack (which would throw
 * FiberError; see fiber_switch_limit.php).
 */

require __DIR__ . '/CooperativeScheduler.php';

Async\SchedulerHook::register('cooperative', new CooperativeScheduler());

/**
 * A one-slot future: a coroutine awaits it, another resolves it. The waiting
 * coroutine records itself; resolve() defers a wake.
 */
final class Future
{
    private ?object $waiter = null;
    private mixed $value = null;
    private bool $ready = false;

    public function await(): mixed
    {
        if (!$this->ready) {
            $this->waiter = Async\Coroutine::current();
            await();   // suspend until resolve() wakes us
        }

        return $this->value;
    }

    public function resolve(mixed $value): void
    {
        $this->value = $value;
        $this->ready = true;

        if ($this->waiter !== null) {
            Async\Coroutine::resume($this->waiter);   // deferred wake
            $this->waiter = null;
        }
    }
}

$future = new Future();

spawn(function () use ($future): void {
    echo "consumer: awaiting the value\n";
    $value = $future->await();
    echo "consumer: got '$value'\n";
});

spawn(function () use ($future): void {
    echo "producer: resolving the future\n";
    $future->resolve('hello');
    echo "producer: done\n";
});

echo "main: end of script\n";
