# Coroutine context in PHP core: worked examples

How the per-coroutine contexts defined by the
[Async Scheduler Hook API](https://github.com/true-async/php-async-core-rfc/blob/main/scheduler_rfc.md) are used inside the PHP core itself. Every example
below runs today in the [TrueAsync engine tree](https://github.com/true-async/php-src/tree/true-async)
and goes through the operations the RFC standardises as hooks: `getInternalContext()`,
`contextFind()`, `contextSet()`, `contextUnset()`.

## Why two contexts

Each coroutine carries two key/value stores:

- the **userland context**: string or object keys, ordinary PHP values; application state such
  as a request id, a tracing span, a locale;
- the **internal context**: numeric keys allocated once per process with
  `zend_async_internal_context_key_alloc()`, values owned by C code; reachable only from C.

The split is a safety boundary, not a convenience. Internal-context values are raw C data: both
examples below store bare pointers (`IS_PTR` zvals) to `ecalloc`'d structures. If the two stores
were merged, ordinary PHP code could reach that state through the same context operations it
uses for its own keys: read a pointer as if it were a value, overwrite it, or unset an entry
whose memory C code still owns. Any of those corrupts C state or silently changes core
behaviour. Keeping the internal context structurally inaccessible from PHP removes that entire
class of failure; no discipline or naming convention is required, the boundary is enforced by
construction.

## Example 1: output buffering, `ob_start()`

Some functions need to keep state tied to a coroutine. An example is `ob_start()`: it pushes a
handler onto a stack, and everything printed afterwards lands in that buffer. With thousands of
coroutines interleaving in one process, a single process-global stack would mix their output. The
fix costs no userland changes: the handler stack moves into the coroutine's internal context.

At startup, the subsystem allocates its process-unique key once:

```c
/* main/output.c (abridged) */
uint32_t php_output_context_key = 0;

static void php_output_async_init(void)
{
    if (php_output_context_key == 0) {
        php_output_context_key = ZEND_ASYNC_INTERNAL_CONTEXT_KEY_ALLOC("php_output_context");
    }
}
```

When `ob_start()` runs while a coroutine is current, the subsystem looks up that coroutine's own
handler stack, lazily creating it on first use and tying its release to the coroutine's finish
event:

```c
static php_output_context_t *php_output_ensure_coroutine_context(zend_coroutine_t *coroutine)
{
    zval *found = ZEND_ASYNC_INTERNAL_CONTEXT_FIND(coroutine, php_output_context_key);
    if (found != NULL) {
        return Z_PTR_P(found);
    }

    php_output_context_t *ctx = ecalloc(1, sizeof(*ctx));
    php_output_init_async_context(ctx);

    zval stored;
    ZVAL_PTR(&stored, ctx);
    ZEND_ASYNC_INTERNAL_CONTEXT_SET(coroutine, php_output_context_key, &stored);

    /* released by a callback on the coroutine's finish event */
    return ctx;
}
```

From then on every output operation resolves the stack through the current coroutine, so two
coroutines that both call `ob_start()` buffer independently and never interleave. One extra
piece completes the picture: a *main-coroutine start handler*
(`php_output_main_coroutine_start_handler()`) copies the handlers already opened by the plain
request flow into the main coroutine's context, so buffering started before concurrency
activates keeps working inside it.

Code: [`main/output.c`](https://github.com/true-async/php-src/blob/true-async/main/output.c),
functions `php_output_async_init()`, `php_output_ensure_coroutine_context()`,
`php_output_main_coroutine_start_handler()`.

## Example 2: `gethostbyname()`, a static-buffer API made coroutine-safe

`gethostbyname()` is a classic C API with a hidden trap: it returns a pointer to a `hostent`
structure the caller does not free, which implementations traditionally back with a static
buffer. One thread, one buffer: fine. Thousands of coroutines resolving names concurrently in
one thread: every resolution would clobber the result another coroutine is still reading.

The async implementation keeps the API contract but moves the buffer into the coroutine's
internal context:

```c
/* main/network_async.c (abridged) */
static int hostent_key = 0;

ZEND_API struct hostent *php_network_gethostbyname_async(const char *name)
{
    zend_coroutine_t *coroutine = ZEND_ASYNC_CURRENT_COROUTINE;
    /* ... resolve via the non-blocking getaddrinfo ... */

    if (UNEXPECTED(hostent_key == 0)) {
        hostent_key = zend_async_internal_context_key_alloc("php_network_hostent");
    }

    /* free the previous result of THIS coroutine, if any */
    zval *previous = zend_async_internal_context_find(coroutine, hostent_key);
    if (previous != NULL) {
        hostent_free(Z_PTR_P(previous));
        ZEND_ASYNC_INTERNAL_CONTEXT_UNSET(coroutine, hostent_key);
    }

    struct hostent *hostent = ecalloc(1, sizeof(struct hostent));
    /* ... fill from the addrinfo list ... */

    zval value;
    ZVAL_PTR(&value, hostent);
    ZEND_ASYNC_INTERNAL_CONTEXT_SET(coroutine, hostent_key, &value);

    /* freed by hostent_free_callback() on the coroutine's finish event */
    return hostent;
}
```

Each coroutine owns exactly one result slot, exactly matching the lifetime rules of the original
API ("valid until the next call"), but the "next call" is now scoped to the same coroutine
rather than the whole process. The cleanup callback registered on the coroutine's finish event
frees the last result when the coroutine ends.

Code:
[`main/network_async.c`](https://github.com/true-async/php-src/blob/true-async/main/network_async.c),
functions `php_network_gethostbyname_async()`, `hostent_free_callback()`.

## The pattern

Both examples follow the same four steps, and any extension can too:

1. allocate a process-unique numeric key once (`zend_async_internal_context_key_alloc()`);
2. on first use in a coroutine, create the state and store it under the key
   (`INTERNAL_CONTEXT_FIND`, then `INTERNAL_CONTEXT_SET`);
3. register a dispose callback on the coroutine's finish event, so the state's lifetime is the
   coroutine's lifetime;
4. resolve every read and write through the current coroutine, never through a true global.

What used to be process-global or static state becomes per-coroutine state with a defined
lifetime, and userland code cannot touch any of it.

## Example 3: a microtask-driven concurrent iterator

A microtask is a callable that runs inside the scheduler's own tick, between coroutine switches;
it never suspends and runs to completion right where the scheduler stands. One use is a
concurrent iterator: a worker coroutine drives a loop over shared state, and a microtask watchdog
re-spawns a replacement worker whenever the current one suspends, so exactly one coroutine ever
drives the loop:

```php
// Pseudocode: spawn() and currentCoroutine() are the scheduler's helpers.
final class ConcurrentIterator
{
    private ?object $owner = null;      // the coroutine currently driving the loop
    private bool $done = false;

    public function __construct(
        private \Iterator $items,
        private \Closure $handler,
    ) {}

    public function start(): void
    {
        Async\SchedulerHook::defer($this->tick(...));
    }

    /** The microtask: it fires only while the current worker is parked. */
    private function tick(): void
    {
        if ($this->done) {
            return;                     // finished: nothing left to staff
        }

        $this->owner = spawn($this->run(...));   // replacement takes the state over
    }

    /** The loop a worker runs. The handler may suspend anywhere. */
    private function run(): void
    {
        $me = currentCoroutine();
        Async\SchedulerHook::defer($this->tick(...));   // arm the watchdog for this run

        while (!$this->done && $this->items->valid()) {
            $item = $this->items->current();
            $key = $this->items->key();
            $this->items->next();       // advance before the handler, like foreach

            ($this->handler)($item, $key);   // may suspend: the microtask then
                                             // staffs a replacement worker
            if ($this->owner !== $me) {
                return;                 // someone took over while we slept:
            }                           // exit at once, exactly one driver
        }

        $this->done = true;             // finished while still the owner
    }
}
```

This is exactly how the PHP engine runs object destructors during GC in concurrent mode.
`gc_destructors_coroutine()` re-arms the microtask and iterates the destructor buffer; if a
destructor suspends, the next tick's microtask (`zend_gc_destructors_coroutine_microtask()`)
spawns a fresh destructor coroutine that continues from the shared index and becomes the owner,
and the displaced one exits through the identity check
(`GC_G(dtor_coroutine) != ZEND_ASYNC_CURRENT_COROUTINE`); see
[zend_gc.c](https://github.com/true-async/php-src/blob/true-async/Zend/zend_gc.c). TrueAsync's
general-purpose concurrent iterator
([iterator.c](https://github.com/true-async/php-async/blob/main/iterator.c)) generalises the
same shape: the iterator structure is itself the microtask, and it staffs worker coroutines up
to a configurable concurrency limit over one shared position.
