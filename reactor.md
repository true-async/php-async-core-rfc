# Reactor C Interface: Discussion Document

- **Status:** Discussion draft (NOT an RFC, NOT a language proposal)
- **Scope:** the C-level interface of the *reactor*, the component that multiplexes
  readiness/completion events for the scheduler described in
  [scheduler_rfc.md](scheduler_rfc.md).
- **Audience:** extension authors and reactor implementers.
- **Companion:** [`Zend/zend_async_API.h`](https://github.com/true-async/php-src), the
  existing production reactor slots, whose model this document contrasts with and proposes
  to complement.

> This is a *shape-finding* document. It sketches a hypothetical `async_io_*` reactor API in C,
> weighs it against the reactor that already lives in `zend_async_API.h`, and shows how it
> would plug into the Scheduler Hook contract. Names, signatures and enum values are open
> for discussion; the intent is to agree on the **model** before anyone freezes an ABI.

---

## 1. Where the reactor sits

The scheduler RFC draws a strict line: **hooks decide *which* coroutine runs next
(policy); the engine performs the switch (mechanism).** The scheduler owns the run queue,
the microtask queue, and coroutine lifecycle. It does *not* own a source of external
events. Something has to tell it "socket #7 is readable now", "the 200 ms timer fired",
"the child process exited", "the worker thread finished". That something is the
**reactor**.

```
   OS  ─────────────►  Reactor  ─────────────►  Scheduler  ─────────────►  Coroutine
 (epoll/kqueue/       (async_io_* API,             (RESUME /             (resumes at its
  IOCP/io_uring)       this document)         ENQUEUE hooks)        suspension point)
```

The reactor is the **leaf** of the concurrency stack. Its callbacks are the only place
where "an OS event happened" is turned into "wake this coroutine". Everything above it
(futures, channels, `await()`, `spawn()`) is built on the scheduler, and the scheduler is
fed by the reactor. Section 9 makes the join concrete.

The reactor is registered exactly like the scheduler: a single registration call at MINIT
fills a table of function-pointer slots (see `zend_async_reactor_register()` in
`zend_async_API.h`). With no reactor registered, the slots are `NULL` and PHP behaves
synchronously. This document only discusses the *shape of the slots*, not the registration
plumbing, which already exists.

---

## 2. Two models: retained events vs. immediate callbacks

`zend_async_API.h` today exposes a **retained-mode, event-object** reactor. You allocate an
event object, attach one or more callback objects to it, start it, and later dispose it:

```c
/* Today (retained mode): three steps, two heap objects */
zend_async_poll_event_t *ev =
    ZEND_ASYNC_NEW_POLL_EVENT(fd, /*socket*/ 0, ASYNC_READABLE);
ev->base.add_callback(&ev->base, my_callback);   /* zend_async_event_callback_t */
ev->base.start(&ev->base);
/* ... later ... */
ev->base.dispose(&ev->base);
```

This model is powerful (multiple listeners per event, replay, `Awaitable` bridging) and it
is the right substrate for engine-level primitives (futures, coroutine completion). But it
is **heavy for foreign code**. A library like **cURL** does not think in event objects. Its
multi interface hands you a raw fd and a direction and asks you to *"call me back when this
fd is ready"*. Mapping that onto allocate-event, attach-callback, start, dispose is three
allocations and a vtable per fd transition, for something that is conceptually one function
pointer.

This document proposes an **immediate-mode, callback-first** family alongside the existing
one:

```c
/* Proposed (immediate mode): one call, one handle, inline typed callback */
async_io_handle_t *h = async_io_poll_new(fd, ASYNC_IO_READABLE, on_ready, conn);
```

The two models are **not** competitors; they are layers. The retained event objects can be
*implemented on top of* the immediate callbacks. The immediate API is what extensions and
foreign libraries should reach for; the event-object API stays for engine primitives that
need replay/multi-listener/Awaitable semantics.

**Design goals of the immediate family**

1. **One call arms; explicit calls tear down.** No separate construct/attach/start dance,
   and no control smuggled through a callback return value: callbacks return `void`; you
   steer the handle with explicit functions (`async_io_pause`, `async_io_cancel`, `async_io_close`, `async_io_dispose`).
2. **Typed callbacks, one per operation.** A read callback takes bytes, a timer callback
   takes nothing, a DNS callback takes an `addrinfo`, a poll callback takes a readiness mask.
   No generic "event kind + union payload" to switch on: the compiler checks the arguments.
   This mirrors libuv (`uv_read_cb`, `uv_timer_cb`, `uv_poll_cb`, `uv_getaddrinfo_cb`).
3. **Foreign-fd friendly.** `async_io_poll_new(fd, ...)` works on an fd the reactor did not open
   (cURL, GnuTLS, a third-party C library). The reactor never assumes ownership unless told.
4. **Allocation-lean on the hot path.** The *caller* supplies read buffers through a separate
   `alloc` callback, so the steady state needs no per-chunk reactor allocation.

---

## 3. Core types and the callback family

There is no universal callback and no union payload. There is one tiny buffer descriptor and
a small set of **typed** callbacks; each operation takes the one that fits it.

```c
typedef struct async_io_handle_s async_io_handle_t;   /* opaque; one per armed operation */

/* A slice of memory: base + length. The only shared data shape. */
typedef struct { char *base; size_t len; } async_io_buf_t;

/* Readiness / direction bitfield, shared by poll and stream ops. */
typedef enum {
    ASYNC_IO_READABLE = 1 << 0,
    ASYNC_IO_WRITABLE = 1 << 1,
    ASYNC_IO_HANGUP   = 1 << 2,  /* POLLHUP: peer hung up   */
    ASYNC_IO_PRIO     = 1 << 3,  /* POLLPRI: urgent/OOB data */
} async_io_ready;
```

### 3.1 The typed callbacks

Every callback takes the `async_io_handle_t *h` it belongs to (so one C function can serve many
handles) plus the `user_data` it was armed with, and returns `void`. Beyond that, each has
exactly the arguments its operation needs:

```c
/* Poll readiness. status < 0 => error (pull it with async_io_error). */
typedef void (*async_io_poll_cb_t)(async_io_handle_t *h, int status, async_io_ready events, void *ud);

/* Read, two callbacks (like uv_read_start):
 *  - alloc: the reactor asks WHERE to put the next chunk; caller fills *out.
 *           Set out->len = 0 to signal back-pressure.
 *  - read : nread > 0 => that many bytes are in buf->base (the buffer alloc gave);
 *           nread == 0 => EOF; nread < 0 => error (async_io_error). */
typedef void (*async_io_alloc_cb_t)(async_io_handle_t *h, size_t suggested, async_io_buf_t *out, void *ud);
typedef void (*async_io_read_cb_t) (async_io_handle_t *h, ssize_t nread, const async_io_buf_t *buf, void *ud);

/* Write completion. status >= 0 => bytes written; status < 0 => error. */
typedef void (*async_io_write_cb_t)(async_io_handle_t *h, ssize_t status, void *ud);

/* Timer / trigger: no payload, they just fire. */
typedef void (*async_io_timer_cb_t)  (async_io_handle_t *h, void *ud);
typedef void (*async_io_trigger_cb_t)(async_io_handle_t *h, void *ud);

/* Signal / process / filesystem: each carries its own natural payload. */
typedef void (*async_io_signal_cb_t) (async_io_handle_t *h, int signum, void *ud);
typedef void (*async_io_process_cb_t)(async_io_handle_t *h, int64_t exit_code, int term_signal, void *ud);
typedef void (*async_io_fs_cb_t)     (async_io_handle_t *h, const char *path, unsigned events, void *ud);

/* DNS: the resolved result is a typed argument, not a void*. */
typedef void (*async_io_getaddrinfo_cb_t)(async_io_handle_t *h, int status, struct addrinfo *res, void *ud);
typedef void (*async_io_getnameinfo_cb_t)(async_io_handle_t *h, int status,
                                    const char *host, const char *service, void *ud);

/* Teardown notification (like uv_close_cb): the last call on a handle. */
typedef void (*async_io_close_cb_t)(async_io_handle_t *h, void *ud);
```

The win over a single `kind`-dispatched callback: the compiler enforces that a read handler
takes bytes and a DNS handler takes an `addrinfo`; there is no `switch (kind)` with mystery
fields that are only valid for some cases; and each handler is small and single-purpose.

### 3.2 Handle accessors

```c
int   async_io_handle_fd(async_io_handle_t *h);      /* the fd/socket, or -1                */
void *async_io_handle_data(async_io_handle_t *h);    /* the user_data it was armed with      */

/* An additional, freely-mutable custom-data slot, separate from user_data.
 * Handy for per-operation state (a protocol cursor) without a second object. */
void  async_io_set_udata(async_io_handle_t *h, void *p);
void *async_io_get_udata(async_io_handle_t *h);
```

### 3.3 Control is explicit (callbacks return `void`)

All steering is done with explicit calls; none of it rides on a return value.

```c
void async_io_pause(async_io_handle_t *h);                    /* stop delivery (back-pressure) */
void async_io_resume(async_io_handle_t *h);                   /* resume delivery               */
void async_io_set_timeout(async_io_handle_t *h, uint64_t ms); /* per-handle deadline           */

/* Change the poll interest set WITHOUT tearing the watcher down or re-arming it.
 * The watcher stays armed; only the directions it waits on change. cURL flips
 * READABLE <-> WRITABLE as a transfer progresses; this is that flip, not a
 * stop-and-start. */
int  async_io_poll_set(async_io_handle_t *h, async_io_ready events);
```

### 3.4 Errors are pulled, not pushed

A failing operation signals it in the callback's `status`/`nread` (negative), and the handler
retrieves the *rich* reason from the handle:

```c
typedef enum { ASYNC_IO_ERR_NONE, ASYNC_IO_ERR_ERRNO, ASYNC_IO_ERR_EAI, ASYNC_IO_ERR_TLS, ASYNC_IO_ERR_URING } async_io_err_domain;

typedef struct {
    async_io_err_domain domain;
    int           code;     /* interpreted per domain           */
    void         *data;     /* optional domain-specific payload  */
} async_io_error_t;

/* The last error on the handle. Valid inside the failing callback. */
async_io_error_t async_io_error(async_io_handle_t *h);
```

Keeping the domain (errno vs `EAI_*` vs a TLS code vs an io_uring code) means DNS and TLS
failures are not squeezed into a bare `errno`. The **cancel reason travels the same way**:
`async_io_cancel(h, reason)` makes the cancelled op's callback fire with a negative status and
`async_io_error(h)` return exactly that `reason` (§10).

### 3.5 Flags

```c
#define ASYNC_IO_F_OWNS_FD  (1u << 0)  /* async_io_close() also close()s the fd            */
#define ASYNC_IO_F_LEVEL    (1u << 2)  /* poll: level-triggered (default edge)       */
#define ASYNC_IO_F_HIDDEN   (1u << 3)  /* not counted as "real pending work" (§10.4) */
```

Every armed handle is by default **visible**: it counts as a piece of real, awaited work. A
handle marked `ASYNC_IO_F_HIDDEN` still runs and still fires its callback, but it is *not* counted
when the reactor decides whether the loop has anything meaningful left to do. Hidden handles
are the reactor's own infrastructure (the cross-thread trigger, an idle-reaper timer, a
signalfd) and any watcher that must not, by itself, keep the process alive. This visible vs.
hidden split is what makes hang and deadlock detection possible (§10.4).

```c
void async_io_set_hidden(async_io_handle_t *h, bool hidden);   /* toggle at any time */
bool async_io_is_hidden(async_io_handle_t *h);
```

---

## 4. Reading and writing

### 4.1 Reading: separate `alloc` and `read` callbacks

The caller owns the read buffer. The reactor asks for one via the `alloc` callback, then
delivers bytes into it via the `read` callback, exactly `uv_read_start`'s shape: buffer
allocation is a separate callback.

```c
async_io_handle_t *async_io_read(async_io_handle_t *stream, async_io_alloc_cb_t alloc, async_io_read_cb_t read, void *ud);
```

```c
static void conn_alloc(async_io_handle_t *h, size_t suggested, async_io_buf_t *out, void *ud)
{
    conn_t *c = ud;
    out->base = c->rbuf + c->rlen;              /* hand over our buffer ...        */
    out->len  = sizeof(c->rbuf) - c->rlen;      /* ... and the room left (0 = stop)*/
}

static void conn_read(async_io_handle_t *h, ssize_t nread, const async_io_buf_t *buf, void *ud)
{
    conn_t *c = ud;
    if (nread > 0) {                            /* bytes in buf->base              */
        c->rlen += nread;
        if (!parser_feed(&c->parser, buf->base, nread)) async_io_close(h, conn_closed);
        else if (c->backpressured)              async_io_pause(h);
    } else if (nread == 0) {                    /* EOF                             */
        conn_flush_and_finish(c);
        async_io_close(h, conn_closed);
    } else {                                    /* nread < 0: error / cancel       */
        conn_fail(c, async_io_error(h));
        async_io_close(h, conn_closed);
    }
}

static void conn_closed(async_io_handle_t *h, void *ud) { conn_free(ud); async_io_dispose(h); }
```

Because the caller allocates, retaining bytes across chunks is just "don't reset the cursor",
no copy out of reactor-owned scratch. This is today's `zend_async_io_alloc_cb_t`, promoted to
a first-class callback of the read operation.

**Multishot is invisible here.** Whether the backend keeps one armed request delivering chunk
after chunk (io_uring multishot) or re-submits per chunk (epoll) is *entirely the backend's
private decision*: the API is the same either way, one `async_io_read` and a stream of `read`
callbacks (mechanics in §11).

### 4.2 Writing: the caller pushes a buffer

`async_io_write` pushes a ready buffer to a file or socket, mirroring `zend_async_io_write_fn`. The
caller already has the bytes; there is no pull loop by default.

```c
/* Optional buffer-release callback for fire-and-forget writes: when the kernel
 * write completes, the reactor calls free_cb(data, h) to release the backing
 * allocation. Same contract as zend_async_io_write_free_cb_t. */
typedef void (*async_io_free_cb_t)(void *data, async_io_handle_t *h);

/* Write `count` bytes from `buf`. On completion the async_io_write_cb fires with
 * status = bytes written (>= 0) or an error (< 0, pull with async_io_error). If
 * free_cb is non-NULL the reactor owns `buf` and releases it on completion:
 * fire-and-forget; `cb` may be NULL then. */
async_io_handle_t *async_io_write(async_io_handle_t *io, const char *buf, size_t count,
                      async_io_free_cb_t free_cb, async_io_write_cb_t cb, void *ud);

/* Vectored: submit N buffers at once (writev/sendmsg). `iov` copied at submit;
 * wire order matches array order. */
async_io_handle_t *async_io_writev(async_io_handle_t *io, const async_io_buf_t *iov, unsigned niov,
                       async_io_free_cb_t free_cb, async_io_write_cb_t cb, void *ud);
```

A streaming *pull* writer (reactor asks the callback for the next chunk when the socket has
room) is a possible **optional** extension, not the default; the default is push, exactly
like the existing API.

### 4.3 Bridging `php_stream`

Almost all existing PHP I/O flows through `php_stream` (the wrapper behind `fopen`,
`fsockopen`, `stream_socket_client`, and every userspace wrapper). To make those paths async
without rewriting them, the reactor offers two conversions in both directions. This is the
integration point: an extension takes a stream a script already opened and drives it through
the `async_io_*` API, or exposes an `async_io_*` handle back to the script as an ordinary stream.

```c
/* PHP stream ─► io handle. Wrap an existing php_stream in an async_io_handle_t bound
 * to its underlying fd/socket, so the core can read/write it asynchronously.
 * The reactor does NOT take ownership of the fd unless ASYNC_IO_F_OWNS_FD is passed;
 * closing the io handle detaches, leaving the php_stream usable synchronously
 * (this is exactly what the on_detach hook on zend_async_io_t already does).
 * Returns NULL if the stream has no pollable descriptor (e.g. a memory stream). */
async_io_handle_t *async_io_from_stream(php_stream *stream, uint32_t flags);

/* io handle ─► PHP stream. Expose an async_io_handle_t to userland as a normal
 * php_stream, so io-based core code can hand a script something it can fread()/
 * fwrite()/stream_select() like any other stream. The stream's operations route
 * through the reactor; blocking reads suspend the current coroutine (§9). */
php_stream *async_io_to_stream(async_io_handle_t *h);
```

`async_io_from_stream` is how a builtin like `fread($sock)` becomes non-blocking: pull the fd
out of the `php_stream`, wrap it, `async_io_read` + suspend (§9.1), and on completion copy back into
the stream's buffer. `async_io_to_stream` is the reverse, letting a reactor-native connection
(say an accepted socket from an async listener) be returned to PHP as a plain stream resource.
The pair keeps the huge existing surface of stream wrappers working while the actual I/O moves
onto the event loop.

---

## 5. Poll: readiness on a foreign fd

The motivating case: **cURL** (and any C library built around `select`/`poll`). The library
owns the fd and the protocol; it only needs the loop to tell it when the fd is ready.

```c
/* Arm a readiness watcher on an already-open descriptor the reactor did NOT
 * open and does NOT own. Level-triggered stays armed until async_io_close. */
async_io_handle_t *async_io_poll_new(int fd, async_io_ready events, async_io_poll_cb_t cb, void *ud);
```

cURL's `CURLMOPT_SOCKETFUNCTION` maps directly:

```c
static int curl_socket_cb(CURL *e, curl_socket_t fd, int what, void *userp, void *socketp)
{
    async_io_handle_t *h = socketp;
    switch (what) {
        case CURL_POLL_IN: case CURL_POLL_OUT: case CURL_POLL_INOUT: {
            async_io_ready ev = (what != CURL_POLL_IN  ? ASYNC_IO_WRITABLE : 0)
                        | (what != CURL_POLL_OUT ? ASYNC_IO_READABLE : 0);
            if (h) async_io_poll_set(h, ev);                        /* just flip interest */
            else { h = async_io_poll_new(fd, ev, on_curl_ready, userp);  /* fd NOT owned  */
                   curl_multi_assign(mh, fd, h); }
            break;
        }
        case CURL_POLL_REMOVE:
            if (h) async_io_close(h, on_curl_closed);   /* stop watching; fd stays open */
            break;
    }
    return 0;
}

static void on_curl_ready(async_io_handle_t *h, int status, async_io_ready events, void *ud)
{
    int running;
    curl_multi_socket_action(ud, async_io_handle_fd(h), to_curl_flags(events), &running);
}
static void on_curl_closed(async_io_handle_t *h, void *ud) { async_io_dispose(h); }
```

Because the watcher was created without `ASYNC_IO_F_OWNS_FD`, `async_io_close` only stops watching; it
does **not** `close()` the descriptor, which cURL still owns.

---

## 6. Timers, signals, processes, filesystem

Each takes its own typed callback. A periodic watcher keeps firing until `async_io_close`.

```c
/* period_ms > 0 => periodic; 0 => one-shot. */
async_io_handle_t *async_io_timer_new(uint64_t timeout_ms, uint64_t period_ms, async_io_timer_cb_t cb, void *ud);
async_io_handle_t *async_io_signal_new(int signum, async_io_signal_cb_t cb, void *ud);
async_io_handle_t *async_io_process_new(zend_process_t pid, async_io_process_cb_t cb, void *ud);
async_io_handle_t *async_io_fs_event(const char *path, unsigned flags, async_io_fs_cb_t cb, void *ud);
```

```c
static void heartbeat(async_io_handle_t *h, void *ud) { send_ping(ud); }  /* async_io_timer_cb_t */
```

---

## 7. Trigger: cross-thread wakeup between event loops

`async_io_trigger` is the one primitive that is **safe to fire from another thread**. It bridges an
event loop on the main thread with work on worker threads (a thread pool, a blocking DNS
resolver, a native library with its own threads). It corresponds to libuv's `uv_async_t` and
to the existing `zend_async_trigger_event_t`.

```c
async_io_handle_t *async_io_trigger_new(async_io_trigger_cb_t cb, void *ud);

/* THREAD-SAFE: the ONLY async_io_* call invokable from another thread. Coalescing:
 * N fires before the loop turns may collapse into one callback (edge). Wakes
 * the loop if it is blocked in async_io_run(). */
int async_io_trigger_fire(async_io_handle_t *h);
```

```c
/* --- worker thread --- */
job->result = do_blocking_work(job);
async_io_trigger_fire(job->trigger);          /* safe cross-thread hop */

/* --- loop thread --- */
static void on_worker_done(async_io_handle_t *h, void *ud)   /* async_io_trigger_cb_t */
{
    job_t *job = ud;
    ZEND_ASYNC_RESUME(job->waiter);     /* now safe to touch engine state */
    async_io_close(h, on_trigger_closed);
}
```

Everything the reactor does is single-threaded *except* `async_io_trigger_fire`. That one crack is
what lets the thread pool deliver results back into the loop without locks in the hot path.

---

## 8. DNS: a separate group

DNS is deliberately its own family: a resolution is a *request/response* with an owned result
that must be explicitly freed, not a stream and not a readiness event. It mirrors
`getaddrinfo`/`getnameinfo` and the existing DNS slots.

```c
async_io_handle_t *async_io_dns_getaddrinfo(const char *node, const char *service,
                                const struct addrinfo *hints,
                                async_io_getaddrinfo_cb_t cb, void *ud);
async_io_handle_t *async_io_dns_getnameinfo(const struct sockaddr *addr, int flags,
                                async_io_getnameinfo_cb_t cb, void *ud);
void async_io_dns_freeaddrinfo(struct addrinfo *ai);
```

```c
static void on_resolved(async_io_handle_t *h, int status, struct addrinfo *res, void *ud)
{
    connect_ctx_t *ctx = ud;
    if (status == 0) {                       /* success: typed addrinfo argument */
        ctx->fd = try_connect(res);
        async_io_dns_freeaddrinfo(res);            /* explicit free                     */
    } else {
        ctx->err = async_io_error(h);              /* domain == ASYNC_IO_ERR_EAI              */
    }
    ZEND_ASYNC_RESUME(ctx->waiter);
    async_io_close(h, on_dns_closed);
}
```

Internally most reactors implement this with the §7 pattern: `getaddrinfo` blocks, so it runs
on a thread pool and the answer is hopped back with an `async_io_trigger`. From the caller's side it
is just an `async_io_getaddrinfo_cb_t`.

---

## 9. Combining the reactor with the Scheduler

The reactor and the scheduler are two halves of one loop. The scheduler (scheduler_rfc.md)
owns coroutines and a run queue; the reactor owns fds and timers. They meet at two points.

### 9.1 Point one: the reactor callback resumes a coroutine

Every callback that backs a coroutine-level operation ends by calling the scheduler's
**RESUME** hook (`ZEND_ASYNC_RESUME`) to move the waiting coroutine from *suspended* back into
the run queue. The reactor never runs coroutine code; it just flips the coroutine to *ready*.

#### The canonical example: `sleep($seconds)`

The simplest reactor/scheduler interaction, and the shape every blocking builtin follows:
arm a timer, suspend, resume from the timer callback. Three steps.

```c
typedef struct { zend_coroutine_t *co; } sleep_await_t;

/* 3. The timer ticked (loop thread): resume the coroutine, tear the timer down. */
static void sleep_wake(async_io_handle_t *h, void *ud)              /* async_io_timer_cb_t  */
{
    sleep_await_t *w = ud;
    ZEND_ASYNC_RESUME(w->co);                                /* scheduler enqueues co */
    async_io_close(h, sleep_closed);
}
static void sleep_closed(async_io_handle_t *h, void *ud) { async_io_dispose(h); }  /* async_io_close_cb_t */

/* PHP: sleep($seconds), runs on the coroutine's stack. */
void php_async_sleep(zend_long seconds)
{
    sleep_await_t w = { ZEND_ASYNC_CURRENT_COROUTINE };

    /* 1. Arm a one-shot timer. */
    async_io_timer_new((uint64_t) seconds * 1000, /*period*/ 0, sleep_wake, &w);

    /* 2. Suspend: hand control to the scheduler. Returns only after sleep_wake()
     *    resumed us. While parked, the scheduler runs other coroutines and, when
     *    none are runnable, blocks in async_io_run() where the timer eventually ticks. */
    ZEND_ASYNC_SUSPEND();
}
```

| Step | In the coroutine | In the scheduler / reactor |
|------|------------------|----------------------------|
| 1. arm | `async_io_timer_new(...)` | reactor adds a timer to the loop |
| 2. suspend | `ZEND_ASYNC_SUSPEND()` | SUSPEND hook: no runnable coroutine, block in `async_io_run()` |
| wait | (parked) | `async_io_run()` sleeps in the kernel until the timer is due |
| 3. resume | (still parked) | timer fires, `sleep_wake()`, `ZEND_ASYNC_RESUME()`, co re-queued, `async_io_run()` returns, scheduler switches back in |
| done | `SUSPEND()` returns | |

#### A core PHP function: `gethostbyname($host)`

The same three steps power a real builtin, non-blocking without changing its PHP signature.
Note the reason pulled from `async_io_error(h)` on failure, including a **cancellation** reason if
the coroutine was cancelled mid-lookup (§10).

```c
typedef struct { zend_coroutine_t *co; zend_string *result; zend_object *error; } dns_await_t;

static void gethostbyname_done(async_io_handle_t *h, int status, struct addrinfo *res, void *ud)
{
    dns_await_t *w = ud;
    if (status == 0) {                                       /* success */
        char ip[INET6_ADDRSTRLEN];
        addrinfo_to_string(res, ip, sizeof ip);
        w->result = zend_string_init(ip, strlen(ip), 0);
        async_io_dns_freeaddrinfo(res);
    } else {                                                 /* failure OR cancellation */
        w->error = async_io_make_exception(async_io_error(h));           /* reason from async_io_error(h) */
    }
    ZEND_ASYNC_RESUME(w->co);
    async_io_close(h, dns_closed);
}
static void dns_closed(async_io_handle_t *h, void *ud) { async_io_dispose(h); }

PHP_FUNCTION(gethostbyname)   /* sketch: arg parsing elided */
{
    zend_string *host = /* ... */;
    dns_await_t w = { ZEND_ASYNC_CURRENT_COROUTINE, NULL, NULL };
    struct addrinfo hints = { .ai_family = AF_UNSPEC, .ai_socktype = SOCK_STREAM };

    async_io_dns_getaddrinfo(ZSTR_VAL(host), NULL, &hints, gethostbyname_done, &w);  /* 1 */
    ZEND_ASYNC_SUSPEND();                                                      /* 2 */

    if (w.error) { zend_throw_exception_object(w.error); RETURN_THROWS(); }
    RETURN_STR(w.result);
}
```

From the script's side nothing changed: `gethostbyname("php.net")` still returns a string, but
while the lookup is in flight the scheduler runs other coroutines. Swap `async_io_dns_getaddrinfo`
for `async_io_timer_new` and you have `sleep`; swap it for `async_io_read` and you have `fread`. One shape,
every blocking builtin.

`ZEND_ASYNC_SUSPEND()` routes through the scheduler's **SUSPEND** hook; `ZEND_ASYNC_RESUME()`
through the **RESUME** hook (the scheduler_rfc example: "enqueue the coroutine"). The error
path uses RESUME's `?Throwable $error` parameter: the reactor turns the `async_io_error_t` into an
exception the scheduler throws at the coroutine's suspension point.

### 9.2 Point two: the scheduler blocks in the reactor when idle

When the run queue drains, the scheduler asks the reactor to block until the next OS event
instead of spinning. In the RFC this is inside the `suspend` hook; in C the reactor exposes:

```c
bool async_io_run(bool no_wait);   /* one loop turn: poll (block unless no_wait), fire
                                callbacks, return true if any handle is still armed. */
bool async_io_alive(void);         /* any VISIBLE (non-hidden) handle still armed? (§10.4)  */
void async_io_stop(void);          /* request the loop to return at the next turn.          */
uint64_t async_io_now(void);       /* cached loop time in ms (no syscall).                  */
```

```c
while (scheduler_has_live_coroutines()) {
    if (run_queue_empty())
        async_io_run(/*no_wait=*/false);   /* BLOCK until an fd/timer fires; callbacks resume coros */
    coroutine_switch_to(run_queue_dequeue());
}
```

Coroutines run until they all park on IO, the run queue empties, the scheduler blocks in
`async_io_run()`, an OS event fires a callback, the callback calls `ZEND_ASYNC_RESUME`, `async_io_run()`
returns with a non-empty queue, the scheduler switches into the woken coroutine. No coroutine
is lost, the thread never busy-waits.

### 9.3 Where each responsibility lives

| Concern                          | Owner       | Mechanism                                   |
|----------------------------------|-------------|---------------------------------------------|
| Which coroutine runs next        | Scheduler   | `suspend` / `enqueue` hooks (RFC)           |
| Performing the context switch    | Engine      | Fiber switch (RFC "mechanism")              |
| When an fd/timer/signal is ready | **Reactor** | `async_io_*` callbacks (this document)            |
| Waking a coroutine on an event   | Both        | reactor callback, then `ZEND_ASYNC_RESUME`  |
| Blocking the thread when idle    | **Reactor** | `async_io_run(no_wait=false)`                      |
| Cross-thread result delivery     | **Reactor** | `async_io_trigger_fire`, callback, RESUME         |

The reactor knows nothing about coroutines or the run queue, only fds and callbacks. The
scheduler knows nothing about epoll or timers, only the run queue and `async_io_run`. The callback
bodies (§9.1) are the only code touching both, and they are tiny.

---

## 10. Lifetime: cancel vs. close vs. free

Teardown is **three distinct operations at three levels**, not one. Conflating them is the
usual source of use-after-free and "the fd closed but I still had a pending write" bugs.

```c
/* 1. CANCEL the in-flight operation. The handle STAYS OPEN and reusable.
 *    The canceller MUST supply a reason; the cancelled op's callback fires with
 *    a negative status and async_io_error(h) returns exactly that reason, so the waiter
 *    learns WHY it was cancelled (a timeout, a parent cancelling a child, a
 *    losing racer), not just a bare -ECANCELED. */
void async_io_cancel(async_io_handle_t *h, async_io_error_t reason);

/* 2. CLOSE the resource (fd/socket) and stop the watcher. ASYNCHRONOUS: an
 *    operation may be in flight, so the reactor drains it (any bytes the kernel
 *    already read are delivered as a final read callback), then closes the fd if
 *    ASYNC_IO_F_OWNS_FD, and finally calls close_cb (like uv_close). After close the
 *    handle is dead as a resource; its MEMORY may still be alive if refs remain. */
void async_io_close(async_io_handle_t *h, async_io_close_cb_t close_cb);

/* 3. Memory management by reference count. async_io_dispose does the -1 itself: it
 *    drops the caller's primary reference (there is no separate "free"). async_io_ref/
 *    async_io_unref bracket any extra owner. Memory is released when the count reaches
 *    zero, guaranteed no earlier than close_cb and never while the kernel still
 *    owns an in-flight request. Usually called from close_cb. */
async_io_handle_t *async_io_ref(async_io_handle_t *h);
void         async_io_unref(async_io_handle_t *h);
void         async_io_dispose(async_io_handle_t *h);   /* == one decref of the owner ref */
```

### 10.1 Why the split: resource vs. memory

`async_io_close` is about the **resource** (the OS descriptor, the watcher, the kernel request);
`async_io_dispose` is about the **memory** (the `async_io_handle_t` allocation). They are separate because a
closed handle can still be *referenced*: by an in-flight kernel completion not yet fired, or
by another owner holding an `async_io_ref`. Freeing memory the instant you close is a use-after-free:
`free(h)`, then the io_uring CQE arrives and dereferences freed memory.

This is the libuv pattern (`uv_close(handle, cb)`, then free in `cb`) and the
`zend_async_API.h` pattern (separate `close`/`dispose` guarded by `ref_count`). The canonical
flow. Note the drained read: because `async_io_close` is asynchronous, bytes the kernel already read
are delivered before the close rather than dropped.

```
async_io_close(h) ─► drain kernel ─► [final read cb] ─► close fd ─► close_cb ─► async_io_dispose(h)
                                   (already-read bytes)              (last call)   (memory gone)
```

### 10.2 The refcount rules

```
refcount(h) = (owner refs: 1 from _new, +N from async_io_ref)
            + (in-flight refs: 1 per submitted-but-not-yet-completed backend op)
```

- Submitting a backend op (an io_uring SQE, a thread-pool DNS job) takes an internal ref.
- Its completion (CQE, trigger) drops that internal ref *after* invoking the callback.
- `async_io_close` flags "dying", cancels in-flight work, refuses new operations, fires `close_cb`.
- `async_io_dispose` drops the owner's ref.
- At zero, the handle is off the loop, off the kernel and unreachable; memory released, once.

That the *in-flight completion* is a first-class reference is what makes destruction safe under
io_uring, where "the kernel still owns this request" is routine (§11).

### 10.3 `async_io_close`/`async_io_dispose` never block

Both return immediately even with a completion pending; they can be called from inside a
callback or during shutdown where blocking is forbidden. The contract: *"you get exactly one
`close_cb` when it is safe; until then, don't touch `h`."*

> **Threads note.** The thread/worker-pool surface (`spawn`, thread-local transfer,
> `zend_async_thread_*`) is intentionally out of scope and belongs in a separate Thread API
> document. The reactor exposes the single cross-thread crack it needs, `async_io_trigger` (§7), and
> treats worker output as data arriving through a trigger. The refcount rules make that safe: a
> worker can hold an `async_io_ref` on the trigger handle for the job's duration.

### 10.4 Visible vs. hidden handles: hang and deadlock detection

Refcount answers "may I free the memory?". It does **not** answer "is the program actually
making progress, or is it stuck?". Those are different questions, and the second one needs a
separate distinction: **visible** vs. **hidden** handles (the `ASYNC_IO_F_HIDDEN` flag, §3.5).

- A **visible** handle is real awaited work: a socket read a coroutine is blocked on, a timer
  a `sleep()` is parked on, a DNS lookup in flight. As long as one exists, the process has a
  legitimate reason to keep running and to keep blocking in `async_io_run()`.
- A **hidden** handle is infrastructure that must never, by itself, keep the process alive:
  the cross-thread `async_io_trigger` the loop always keeps armed, an idle-reaper timer, a signalfd.
  These are always "armed" but they are not work; they are plumbing.

`async_io_alive()` counts only visible handles. This gives the scheduler a precise stuck-detector.
Consider the deadlock case: every coroutine is suspended, the run queue is empty, so the
scheduler calls `async_io_run(no_wait=false)` and the thread goes to sleep in the kernel. Without
the distinction it would sleep **forever**, because the always-armed `async_io_trigger` keeps the
loop technically "alive". With it, the scheduler can ask a sharper question:

```c
/* Nothing runnable, and about to block. Is there any REAL reason to wait? */
if (run_queue_empty() && !async_io_alive()) {
    /* Only hidden infrastructure is armed: no coroutine can ever be woken.
     * This is a deadlock, not idleness. Report it instead of hanging. */
    zend_async_report_deadlock();       /* dump who is waiting on what */
    return;
}
async_io_run(/*no_wait=*/false);
```

The same signal drives **hang diagnostics**: a monitoring timer (itself hidden, so it does not
perturb the count) can periodically check `async_io_alive()` against how long the loop has been
parked, and when a visible handle has been pending far past its expected deadline, walk the
waiters and print "coroutine #7 blocked on socket #12 (readable) for 30s" (the
`awaiting_info` diagnostics the scheduler RFC describes). Hidden handles are excluded so the
reactor's own always-on plumbing never looks like a stuck operation.

In short: **refcount decides memory lifetime; the visible/hidden flag decides whether the
program still has work to do.** Keeping them separate is what lets the engine tell "quietly
waiting for IO" apart from "deadlocked".

---

## 11. Sketch: implementing `async_io_*` on io_uring

"Handle passed to every callback", "caller-provided buffers", and "refcount pins the request
until completion" map onto the submit/complete ring cleanly. A rough sketch, not a backend.

### 11.1 The request struct is the SQE's `user_data`

Each submitted op carries a small request struct; its address is the SQE's 64-bit `user_data`,
handed back by the CQE. It pins the handle and remembers which typed callback to dispatch to:

```c
typedef struct {
    async_io_handle_t *h;          /* holds an in-flight ref on this handle       */
    uint8_t      op;         /* which typed callback to dispatch (read/write/...) */
    async_io_buf_t     buf;        /* for read/write                              */
} io_uring_req_t;

static void submit(async_io_handle_t *h, io_uring_req_t *r) {
    async_io_ref(h);                                  /* in-flight ref (§10.2) */
    struct io_uring_sqe *sqe = io_uring_get_sqe(&ring);
    /* ... prep the op onto sqe ... */
    io_uring_sqe_set_data(sqe, r);
}
```

### 11.2 One completion loop dispatches to the typed callbacks

`async_io_run(no_wait)` *is* the CQE reap loop. It blocks in `io_uring_submit_and_wait`, then for
each completion dispatches to the handle's typed callback for that op. For a read it first
asks the `alloc` callback for a buffer and submits *that* to the kernel:

```c
bool async_io_run(bool no_wait) {
    io_uring_submit_and_wait(&ring, no_wait ? 0 : 1);

    struct io_uring_cqe *cqe; unsigned head; unsigned n = 0;
    io_uring_for_each_cqe(&ring, head, cqe) {
        io_uring_req_t *r = io_uring_cqe_get_data(cqe);
        async_io_handle_t    *h = r->h;
        void           *ud = async_io_handle_data(h);

        if (cqe->res < 0) set_error(h, ASYNC_IO_ERR_ERRNO, cqe->res);

        switch (r->op) {                                    /* typed dispatch */
            case OP_READ:  h->read_cb(h, cqe->res, &r->buf, ud); break;
            case OP_WRITE: h->write_cb(h, cqe->res, ud);         break;
            case OP_POLL:  h->poll_cb(h, cqe->res, to_ready(cqe), ud); break;
            case OP_TIMER: h->timer_cb(h, ud);                   break;
            /* ... */
        }

        if (!(cqe->flags & IORING_CQE_F_MORE)) {   /* not multishot */
            async_io_unref(h);                           /* drop in-flight ref (§10.2) */
            if (!dying(h) && r->op == OP_READ) rearm_read(h, r);  /* alloc + submit */
            else free(r);
        }
        n++;
    }
    io_uring_cq_advance(&ring, n);
    return async_io_alive();
}
```

**Multishot lives entirely here**: the `IORING_CQE_F_MORE` branch is the only place the word
appears; the API above never mentions it. Multishot kernel gives one armed request and a
stream of CQEs; old kernel / epoll re-submits per chunk. Same `async_io_read`, same callbacks.

### 11.3 Op-by-op mapping

| `async_io_*` call            | io_uring op                                   | Notes                                             |
|------------------------|-----------------------------------------------|---------------------------------------------------|
| `async_io_poll_new`          | `IORING_OP_POLL_ADD` (+ `IORING_POLL_ADD_MULTI`) | Multishot poll = stays armed.                     |
| `async_io_read`              | `IORING_OP_RECV`/`READ` (multishot + provided buffers) | Buffer from `alloc` cb; `nread==0` is EOF. |
| `async_io_write` / `async_io_writev` | `IORING_OP_SEND`/`WRITE`/`WRITEV`           | Caller's buffer; `free_cb` released on the CQE.    |
| `async_io_timer_new`         | `IORING_OP_TIMEOUT` (`IORING_TIMEOUT_MULTISHOT`) | Periodic = multishot timeout.                     |
| `async_io_signal_new`        | `signalfd` + `IORING_OP_POLL_ADD`             |                                                   |
| `async_io_process_new`       | `pidfd_open` + `IORING_OP_POLL_ADD`           | pidfd readable means child exited.                |
| `async_io_trigger_new/_fire` | `eventfd` + poll, or `IORING_OP_MSG_RING`     | `async_io_trigger_fire` is the only cross-thread path.  |
| `async_io_dns_getaddrinfo`   | thread-pool job, then `async_io_trigger_fire`       | `getaddrinfo` blocks; run off-ring, hop back (§7).|
| `async_io_cancel`            | `IORING_OP_ASYNC_CANCEL`                      | CQE (`-ECANCELED`) fires the cb with the reason.  |
| `async_io_close`             | `ASYNC_CANCEL` + `close(fd)` after drain      | Then `close_cb`.                                  |
| `async_io_dispose`              | (no SQE) memory release at refcount zero      | Last in-flight CQE drops the final ref.           |

Lifetime and cancellation fall out of the refcount model: `async_io_close`/`async_io_cancel` submit an
`ASYNC_CANCEL`, the cancelled op still produces a CQE, that CQE drops the in-flight ref exactly
like a normal completion, and only then does the handle reach zero. The kernel never writes
into freed memory because the request struct outlives every SQE that references it.

---

## 12. Known hazards and how the model addresses them

1. **Use-after-free on in-flight completion (io_uring).** Freeing a request while a CQE is
   still coming. *Addressed* by the in-flight reference (§10.2): the handle cannot reach zero
   while the kernel owns a request.

2. **Confusing close with free.** `async_io_dispose` on a handle whose write is still in the kernel.
   *Addressed* by the three-way split (§10): `async_io_close` drains and closes the resource;
   `async_io_dispose` only releases memory, gated by refcount, never before `close_cb`.

3. **Reentrancy: tearing down inside a callback.** *Addressed* by: `async_io_close` only flags dying
   and starts async teardown; `close_cb` is always a later, separate call, never nested.

4. **The read buffer is the caller's, until when?** The caller must not free the buffer it
   handed to `alloc` while a read is armed, or the kernel writes into freed memory.
   *Mitigation*: tie the buffer's lifetime to the handle; release it in `close_cb`.

5. **`async_io_trigger` coalescing.** N fires can collapse into one callback (edge, like `uv_async`).
   *Mitigation*: the handler drains a thread-safe queue until empty; never assume 1:1 (§7).

6. **Cross-thread rule violations.** Every call except `async_io_trigger_fire` is loop-thread-only.
   *Mitigation*: a hard invariant; the Thread API must funnel everything back via `async_io_trigger`.

7. **Refcount leaks.** Every `async_io_ref` needs a matching `async_io_unref`. *Mitigation*: keep `async_io_ref`
   rare; the handle is already passed to every callback, so refs are only for stashing it
   outside the callback.

8. **Cancel reason lost.** A bare `-ECANCELED` tells the waiter nothing. *Addressed* by
   `async_io_cancel(h, reason)` (§10): the reason surfaces through `async_io_error(h)` in the callback.

9. **Head-of-line blocking on the loop thread.** A slow callback stalls the loop.
   *Mitigation*: none at the reactor level; offload heavy work to the Thread API and feed back
   via `async_io_trigger`.

---

## References

- [scheduler_rfc.md](scheduler_rfc.md): the Scheduler Hook API this reactor feeds.
- [SCHEDULER.md](SCHEDULER.md): exact engine invocation points for the scheduler.
- `Zend/zend_async_API.h`: the existing retained-mode reactor slots and registration.
- libuv `uv_read_cb` / `uv_timer_cb` / `uv_poll_cb` / `uv_getaddrinfo_cb` / `uv_close`: the
  closest existing-art for the typed-callback family and the close/free split.
- Linux `io_uring`: `IORING_OP_POLL_ADD` (multishot), `IORING_OP_RECV`/`SEND`,
  `IORING_OP_TIMEOUT`, `IORING_OP_MSG_RING`, `IORING_OP_ASYNC_CANCEL` (§11).
