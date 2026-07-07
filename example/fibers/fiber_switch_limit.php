<?php

/**
 * Why the scheduler funnels every switch through one central point (the scheduler).
 *
 *   php example/fiber_switch_limit.php
 *
 * Plain fibers (no scheduler in this file) obey a strict stack discipline:
 * a fiber can only suspend to its *immediate* resumer, and a fiber that is
 * currently running cannot be resumed at all. Build a 4-deep hierarchy and
 * both limits show up — this is exactly what routing through the scheduler avoids.
 */

// --- 1. A deep suspend lands on the "wrong" fiber -----------------------
// main -> A -> B -> C -> D, each starting the next. When D suspends, control
// does NOT go back to main: it goes to C, D's immediate resumer.

$d = new Fiber(function (): void {
    echo "   D: suspend (hoping to land in main)\n";
    Fiber::suspend();
    echo "   D: resumed — never printed, nobody resumes D\n";
});

$c = new Fiber(function () use ($d): void {
    echo "  C: start D\n";
    $d->start();
    echo "  C: >>> control came back to ME (C), not to main <<<\n";
});

$b = new Fiber(function () use ($c): void {
    echo " B: start C\n";
    $c->start();
    echo " B: back in B\n";
});

$a = new Fiber(function () use ($b): void {
    echo "A: start B\n";
    $b->start();
    echo "A: back in A\n";
});

$a->start();

echo "main: back in main — D is stranded, suspended forever\n\n";

// --- 2. Resuming a still-running ancestor is forbidden ------------------
// main -> X -> Y on the stack; Y tries to resume X, which is still running.

$x = null;

$y = new Fiber(function () use (&$x): void {
    echo "  Y: try to resume X (an ancestor that is still running)\n";

    try {
        $x->resume();
    } catch (FiberError $e) {
        echo "  Y: FiberError -> {$e->getMessage()}\n";
    }
});

$x = new Fiber(function () use ($y): void {
    echo "X: start Y\n";
    $y->start();
    echo "X: back in X\n";
});

$x->start();
