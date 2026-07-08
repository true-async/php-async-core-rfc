<?php

/**
 * VARIANT B: cooperative scheduler on Continuations (symmetric).
 *
 * Two layers, as in the RFC. A Continuation is the low-level switch primitive;
 * a Coroutine is the schedulable unit the scheduler builds on top of it. The
 * scheduler mints a Continuation through the createContinuation mandate and
 * wraps it into its own Coroutine object: the ready queue holds Coroutines,
 * never raw Continuations. Switching goes directly into the Continuation with
 * $coroutine->continuation->switchTo(): a symmetric jump, no central hub.
 *
 * The system normalises itself: the main flow is started by the engine, not
 * the scheduler, so at its first yield onSuspend() captures the running
 * context via currentContinuation and wraps it into a Coroutine too (isMain).
 * onSuspend() returns it, so the engine records the main coroutine as
 * current, not null.
 *
 * The mandate (createContinuation + currentContinuation + currentCoroutine)
 * arrives only in onLaunch(), so nobody but the scheduler can mint or capture
 * continuations.
 */

/** The scheduler's own coroutine: wraps the Continuation that backs it. */
final class Coroutine
{
    public Context $context;
    public Context $internalContext;

    public function __construct(
        public readonly \Async\Continuation $continuation,
        public readonly bool $isMain = false,
    ) {
        $this->context = new Context();
        $this->internalContext = new Context();
    }
}

/** A per-coroutine key/value store: string keys, or object keys by identity. */
final class Context
{
    public array $byString = [];
    public \SplObjectStorage $byObject;

    public function __construct()
    {
        $this->byObject = new \SplObjectStorage();
    }
}

final class ContinuationScheduler implements \Async\Scheduler
{
    public static ContinuationScheduler $instance;

    private \SplQueue $ready;              // Coroutine objects, never raw Continuations
    private \SplQueue $microtasks;         // onDefer() queue, drained once per tick
    private ?Coroutine $main = null;       // the adopted main flow

    /** The mandate: held only by the scheduler. */
    private \Closure $createContinuation;  // createContinuation(callable $entry): Continuation
    private \Closure $currentContinuation; // currentContinuation(): Continuation
    private \Closure $currentCoroutine;    // currentCoroutine(): ?object

    public function __construct()
    {
        self::$instance = $this;
        $this->ready = new \SplQueue();
        $this->microtasks = new \SplQueue();
    }

    /** The engine hands the mandate once, at start. */
    public function onLaunch(
        \Closure $createContinuation,
        \Closure $currentContinuation,
        \Closure $currentCoroutine,
    ): ?object {
        $this->createContinuation = $createContinuation;
        $this->currentContinuation = $currentContinuation;
        $this->currentCoroutine = $currentCoroutine;
        return null;
    }

    /** Spawn: mint a Continuation and wrap it into the scheduler's Coroutine. */
    public function spawn(callable $task): Coroutine
    {
        $coroutine = new Coroutine(($this->createContinuation)($task));
        $this->ready->enqueue($coroutine);
        return $coroutine;
    }

    /**
     * The yielding flow hands us control. Normalise it first: on its first
     * yield the main flow has no Coroutine yet, so capture its Continuation
     * and wrap it. Then run one tick of microtasks and switch into each ready
     * coroutine in turn: a direct symmetric jump, no intermediary.
     */
    public function onSuspend(bool $fromMain, bool $isBailout): ?object
    {
        $self = ($this->currentCoroutine)();

        if ($self === null) {
            $self = $this->main = new Coroutine(($this->currentContinuation)(), isMain: true);
        }

        if (!$isBailout) {
            while (!$this->ready->isEmpty() || !$this->microtasks->isEmpty()) {
                while (!$this->microtasks->isEmpty()) {
                    ($this->microtasks->dequeue())();       // one-shot, this tick
                }

                if (!$this->ready->isEmpty()) {
                    $this->ready->dequeue()->continuation->switchTo();
                }
            }
        }

        return $self;   // recorded as current: the main coroutine at end of main
    }

    public function onEnqueue(object $coroutine, ?\Throwable $error = null): bool
    {
        $this->ready->enqueue($coroutine);
        return true;
    }

    // This scheduler runs only its own coroutines, so it adopts no foreign
    // fibers (a Revolt/AMPHP-hosting scheduler would return a coroutine here).
    public function onFiber(\Fiber $fiber): ?object
    {
        return null;
    }

    public function onShutdown(): bool { return true; }

    /** Microtasks: the engine stores nothing, the queue lives here. */
    public function onDefer(callable $task): bool
    {
        $this->microtasks->enqueue($task);
        return true;
    }

    public function onWaitInfo(object $coroutine, string $info): bool
    {
        return true;    // this demo does not track wait descriptions
    }

    public function getContext(object $coroutine): object
    {
        return $coroutine->context;
    }

    public function getInternalContext(object $coroutine): object
    {
        return $coroutine->internalContext;
    }

    public function contextFind(object $context, mixed $key): mixed
    {
        return is_object($key)
            ? ($context->byObject[$key] ?? null)
            : ($context->byString[$key] ?? null);
    }

    public function contextSet(object $context, mixed $key, mixed $value): bool
    {
        if (is_object($key)) {
            $context->byObject[$key] = $value;
        } else {
            $context->byString[$key] = $value;
        }

        return true;
    }

    public function contextUnset(object $context, mixed $key): bool
    {
        if (is_object($key)) {
            unset($context->byObject[$key]);
        } else {
            unset($context->byString[$key]);
        }

        return true;
    }
}

// --- user-facing helpers -----------------------------------------------------

function spawn(callable $task): Coroutine
{
    return ContinuationScheduler::$instance->spawn($task);
}

// --- demo ----------------------------------------------------------------------

\Async\SchedulerHook::register('continuation', new ContinuationScheduler());

function worker(string $name, int $steps): void
{
    for ($i = 1; $i <= $steps; $i++) {
        echo "    [$name] step $i\n";
    }
}

echo "main: spawn A, B (coroutines wrapping continuations)\n";
$a = spawn(fn () => worker('A', 3));
$b = spawn(fn () => worker('B', 2));

// A context value attached to a coroutine before it runs.
ContinuationScheduler::$instance->contextSet($a->context, 'request-id', 'r-42');

// A microtask: one-shot, runs on the scheduler's next tick, before the switches.
\Async\SchedulerHook::defer(fn () => print "    [defer] microtask runs first\n");

echo "main: end: one tick of microtasks, then a direct switch into each coroutine\n";
