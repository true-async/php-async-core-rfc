# Reactor C Interface — Discussion Document

- **Status:** Discussion draft (NOT an RFC, NOT a language proposal)
- **Scope:** the C-level interface of the *reactor* — the component that multiplexes
  readiness/completion events for the scheduler described in
  [scheduler_rfc.md](scheduler_rfc.md).
- **Audience:** extension authors and reactor implementers.
- **Companion:** [`Zend/zend_async_API.h`](https://github.com/true-async/php-src) — the
  existing production reactor slots, whose model this document contrasts with and proposes
  to complement.

> This is a *shape-finding* document. It sketches a hypothetical `io_*` reactor API in C,
> weighs it against the reactor that already lives in `zend_async_API.h`, and shows how it
> would plug into the Scheduler Hook contract. Names, signatures and enum values are open
> for discussion; the intent is to agree on the **model** before anyone freezes an ABI.
> Section 14 records the design decisions taken so far.

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
 (epoll/kqueue/       (io_* API,               (RESUME /                 (resumes at its
  IOCP/io_uring)       this document)           ENQUEUE hooks)            suspension point)
```

The reactor is the **leaf** of the concurrency stack. Its callbacks are the only place
where "an OS event happened" is turned into "wake this coroutine". Everything above it —
futures, channels, `await()`, `spawn()` — is built on the scheduler, and the scheduler is
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
/* Today (retained mode) — three steps, two heap objects */
zend_async_poll_event_t *ev =
    ZEND_ASYNC_NEW_POLL_EVENT(fd, /*socket*/ 0, ASYNC_READABLE);
ev->base.add_callback(&ev->base, my_callback);   /* zend_async_event_callback_t */
ev->base.start(&ev->base);
/* ... later ... */
ev->base.dispose(&ev->base);
```

This model is powerful — multiple listeners per event, replay, `Awaitable` bridging — and
it is the right substrate for engine-level primitives (futures, coroutine completion). But
it is **heavy for foreign code**. A library like **cURL** does not think in event objects.
Its multi interface hands you a raw fd and a direction and asks you to *"call me back when
this fd is ready"*. Mapping that onto allocate-event → attach-callback → start → dispose is
three allocations and a vtable per fd transition, for something that is conceptually one
function pointer.

This document proposes an **immediate-mode, callback-first** family alongside the existing
one:

```c
/* Proposed (immediate mode) — one call, one handle, inline callback */
io_handle_t *h = io_poll_new(fd, IO_READABLE, on_ready, conn);
/* on_ready(h, IO_EV_READY, ...) fires every time fd is readable */
```

The two models are **not** competitors; they are layers. The retained event objects can be
*implemented on top of* the immediate callbacks (an event object is just a handle whose
callback fans out to a vector of listeners). The immediate API is what extensions and
foreign libraries should reach for; the event-object API stays for engine primitives that
need replay/multi-listener/Awaitable semantics.

**Design goals of the immediate family**

1. **One call arms; explicit calls tear down.** No separate construct/attach/start dance,
   and no control smuggled through a callback return value — the callback is `void`; you
   steer the handle with explicit functions (`io_pause`, `io_cancel`, `io_close`, `io_free`).
2. **One handler, many phases.** A single callback per handle handles readiness, buffer
   allocation, data, completion, EOF, error and teardown — dispatched by a `kind` argument.
   This is the crux of the design (Section 4).
3. **Foreign-fd friendly.** `io_poll_new(fd, …)` works on an fd the reactor did not open
   (cURL, GnuTLS, a third-party C library). The reactor never assumes ownership unless told.
4. **Allocation-lean on the hot path.** The *caller* supplies read buffers (via an
   allocation phase of the same callback), so the steady state needs no per-chunk reactor
   allocation.

---

## 3. Core types

```c
typedef struct io_handle_s io_handle_t;   /* opaque; one per armed operation */

/* Why the callback fired. One handler switches on this. */
typedef enum {
    IO_EV_READY  = 1,  /* poll: fd is now readable/writable (see slice->events) */
    IO_EV_ALLOC  = 2,  /* read: the reactor asks the CALLER for a buffer to fill */
    IO_EV_DATA   = 3,  /* read: a chunk arrived in the caller-provided buffer     */
    IO_EV_WROTE  = 4,  /* write: the buffer you supplied was handed to the kernel */
    IO_EV_EOF    = 5,  /* peer closed the read side / end of stream               */
    IO_EV_DONE   = 6,  /* one-shot op finished (timer fired, dns resolved, ...)   */
    IO_EV_ERROR  = 7,  /* operation failed; pull details with io_error(h)         */
    IO_EV_CLOSE  = 8,  /* resource closed — LAST event; safe to io_free the memory */
} io_event_kind;

/* Readiness / direction bitfield, shared by poll and stream ops. */
typedef enum {
    IO_READABLE = 1 << 0,
    IO_WRITABLE = 1 << 1,
    IO_HANGUP   = 1 << 2,  /* POLLHUP  — peer hung up             */
    IO_PRIO     = 1 << 3,  /* POLLPRI  — urgent/OOB data          */
} io_ready;

/* Payload passed to every callback. Which fields are live depends on `kind`.
 * The reactor owns this struct; it is valid only for the duration of the call.
 *   IO_EV_ALLOC : the callback WRITES base/len — where the next read should land.
 *   IO_EV_DATA  : base/len is the caller's buffer, now holding `len` bytes.
 *   IO_EV_WROTE : len = bytes actually written.
 *   IO_EV_READY : events = which directions are ready.
 *   IO_EV_DONE  : extra = op-specific result (e.g. struct addrinfo*).
 * Errors are NOT carried here — pull them with io_error(h) (see §3.3). */
typedef struct {
    char       *base;
    size_t      len;
    io_ready    events;
    void       *extra;   /* per-op payload (e.g. struct sockaddr* for recvfrom) */
} io_slice_t;

/* THE unified callback — returns void. Control is done with explicit calls
 * (§3.2), never through a return value. */
typedef void (*io_cb_t)(io_handle_t *h,
                        io_event_kind  kind,
                        io_slice_t    *slice,
                        void          *user_data);
```

The single `io_cb_t` signature is deliberately the *only* callback type in the immediate
API. A poll watcher, a streaming read, a write, a timer, a signal handler and a DNS lookup
all use the same function-pointer type. The `kind` argument tells the handler which role it
is being called in; the `slice` carries that role's payload. This is the "1 handler for many
cases" the design is built around. The callback returns nothing — steering the handle is
done with explicit functions, so control is never ambiguous.

### 3.1 The handle is passed to every callback

Every `io_cb_t` receives the `io_handle_t *h` it belongs to as its first argument. This is
deliberate and load-bearing: a single C function can serve as the callback for many handles
(one `on_ready` for a thousand cURL sockets), and it needs the handle to know *which* one
fired, to read the fd, to change the interest set, or to tear it down. A small accessor
surface keeps the handle opaque while making it useful from inside the callback:

```c
int      io_handle_fd(io_handle_t *h);      /* the fd/socket, or -1                */
void    *io_handle_data(io_handle_t *h);    /* the user_data it was armed with      */

/* An additional, freely-mutable custom-data slot on the handle, separate from
 * the construction-time user_data. Handy for attaching per-operation state
 * (e.g. a protocol cursor) without a second heap object. */
void     io_set_udata(io_handle_t *h, void *p);
void    *io_get_udata(io_handle_t *h);
```

Because the handle is always in hand, control operations never need a separate lookup table
keyed by fd — the callback already has the object.

### 3.2 Control is explicit — the callback returns `void`

All steering is done with explicit calls; none of it rides on a return value. This was a
deliberate choice (§14, decision 2): a `void` callback cannot accidentally couple "what
happened" to "what to do next".

```c
/* Back-pressure: stop / resume delivery on this handle. */
void io_pause(io_handle_t *h);
void io_resume(io_handle_t *h);

/* Poll interest set (cURL flips READABLE<->WRITABLE as a transfer progresses). */
int  io_poll_set(io_handle_t *h, io_ready events);

/* Per-handle deadline. Fires IO_EV_ERROR (timed out) if the current operation
 * does not complete within `ms`. A separate call, not an argument on every op
 * (§14, decision 5). 0 clears it. */
void io_set_timeout(io_handle_t *h, uint64_t ms);
```

Teardown — `io_cancel`, `io_close`, `io_free` — is its own topic and is specified in §10.

### 3.3 Errors are pulled, not pushed

When an operation fails the callback fires with `IO_EV_ERROR`, but the `io_slice_t` carries
no error fields. The handler retrieves the error from the handle instead (§14, decision 3):

```c
/* Error domains keep EAI_* (DNS), TLS library codes and errno distinguishable
 * instead of being flattened into one lossy int. */
typedef enum { IO_ERR_NONE, IO_ERR_ERRNO, IO_ERR_EAI, IO_ERR_TLS, IO_ERR_URING } io_err_domain;

typedef struct {
    io_err_domain domain;
    int           code;     /* interpreted per domain            */
    void         *data;     /* optional domain-specific payload   */
} io_error_t;

/* The last error on the handle, or {IO_ERR_NONE,0,NULL}. Valid inside the
 * IO_EV_ERROR callback (and until the next operation on the handle). */
io_error_t io_error(io_handle_t *h);
```

Pulling the error keeps the hot path (the common `IO_EV_DATA`/`IO_EV_WROTE` deliveries) free
of error bookkeeping, and keeps the domain intact so DNS and TLS failures are not squeezed
into a bare `errno`.

### 3.4 Flags

```c
#define IO_F_OWNS_FD  (1u << 0)  /* io_close() also close()s the fd  */
#define IO_F_LEVEL    (1u << 2)  /* poll: level-triggered (default edge) */
```

Flags are a trailing `uint32_t` on the `_ex` forms so the common call stays short.

---

## 4. One handler, many phases — the streaming callback

The heart of the proposal is that **`io_read` does not take a buffer** — it takes the
unified callback, and the callback both *provides* the destination buffer (the `IO_EV_ALLOC`
phase) and *consumes* the bytes (`IO_EV_DATA`). This is the cURL model: the reactor asks the
caller where to put the data, then tells the caller it arrived. It collapses what would
otherwise be several separate callbacks into one branchy handler that reads like a protocol
state machine.

### 4.1 Reading — the caller allocates, the reactor fills

```c
io_handle_t *io_read(io_handle_t *stream, io_cb_t cb, void *user_data);
```

The reactor calls the handler with `IO_EV_ALLOC` to ask for a buffer, then `IO_EV_DATA` when
that buffer holds bytes, `IO_EV_EOF` at end of stream, `IO_EV_ERROR` on failure, and
`IO_EV_CLOSE` at teardown:

```c
static void on_conn(io_handle_t *h, io_event_kind kind, io_slice_t *s, void *ud)
{
    conn_t *c = ud;
    switch (kind) {
        case IO_EV_ALLOC:                      /* reactor: "where do I put bytes?" */
            s->base = c->rbuf + c->rlen;        /* caller hands over its buffer ... */
            s->len  = sizeof(c->rbuf) - c->rlen;/* ... and how much room is left     */
            break;                              /* (set len=0 to signal back-pressure)*/

        case IO_EV_DATA:                        /* s->base/s->len : bytes are here  */
            c->rlen += s->len;
            if (!parser_feed(&c->parser, s->base, s->len))
                io_close(h);                    /* protocol error: tear down        */
            else if (c->backpressured)
                io_pause(h);                    /* stop reading until we drain      */
            break;

        case IO_EV_EOF:                         /* peer closed cleanly              */
            conn_flush_and_finish(c);
            io_close(h);
            break;

        case IO_EV_ERROR: {                     /* pull the reason from the handle  */
            io_error_t e = io_error(h);
            conn_fail(c, e);
            io_close(h);
            break;
        }

        case IO_EV_CLOSE:                       /* resource gone — release memory   */
            conn_free(c);
            io_free(h);
            break;

        default: break;
    }
}
```

The caller owns the read buffer, so retaining bytes across chunks is just "don't reset the
cursor" — no copy out of a reactor-owned scratch. This is the same idea as today's
`zend_async_io_alloc_cb_t`, folded into the unified callback as the `IO_EV_ALLOC` phase.

**Multishot is invisible here.** Whether the backend keeps one armed request delivering
chunk after chunk (io_uring multishot) or re-submits per chunk (epoll) is *entirely the
backend's private decision* — the API surface is identical either way: one `io_read`, a
stream of `IO_EV_DATA` (§14, decision 7; mechanics in §11).

### 4.2 Writing — the caller pushes a buffer

`io_write` pushes a ready buffer to a file or socket, mirroring `zend_async_io_write_fn`
(§14, decision 4). The caller already has the bytes; there is no pull loop by default.

```c
/* Optional buffer-release callback for fire-and-forget writes: when the kernel
 * write completes, the reactor calls free_cb(data, h) to release the backing
 * allocation. Same contract as zend_async_io_write_free_cb_t. */
typedef void (*io_free_cb_t)(void *data, io_handle_t *h);

/* Write `count` bytes from `buf` to a file or socket handle. Completion is
 * reported through the handle's callback: IO_EV_WROTE (slice->len = bytes
 * written) on success, IO_EV_ERROR on failure (pull it with io_error). If
 * free_cb is non-NULL the reactor owns `buf` and releases it on completion —
 * fire-and-forget, the caller does not wait. */
io_handle_t *io_write(io_handle_t *io, const char *buf, size_t count,
                      io_free_cb_t free_cb, void *data);

/* Vectored: submit N buffers at once (writev/sendmsg). `iov` is copied at
 * submit; ordering on the wire matches array order. */
io_handle_t *io_writev(io_handle_t *io, const io_slice_t *iov, unsigned niov,
                       io_free_cb_t free_cb, void *data);
```

A streaming *pull* writer (reactor asks the callback for the next chunk when the socket has
room) is a possible **optional** extension — an `IO_EV_DRAIN` phase on the same callback —
but it is not the default `io_write`; the default is push, exactly like the existing API.

---

## 5. Poll — readiness on a foreign fd

The motivating case: **cURL** (and any C library built around `select`/`poll`). The library
owns the fd and the protocol; it only needs the loop to tell it when the fd is ready.

```c
/* Arm a readiness watcher on an already-open descriptor the reactor did NOT
 * open and does NOT own. `events` is IO_READABLE | IO_WRITABLE (| IO_PRIO).
 * The callback fires with IO_EV_READY; slice->events says which directions
 * are ready right now. Level-triggered stays armed until io_close. */
io_handle_t *io_poll_new(int fd, io_ready events, io_cb_t cb, void *user_data);
```

cURL's `CURLMOPT_SOCKETFUNCTION` maps directly:

```c
static int curl_socket_cb(CURL *e, curl_socket_t fd, int what,
                          void *userp, void *socketp)
{
    io_handle_t *h = socketp;
    switch (what) {
        case CURL_POLL_IN:    /* want readable  */
        case CURL_POLL_OUT:   /* want writable  */
        case CURL_POLL_INOUT: {
            io_ready ev = (what != CURL_POLL_IN  ? IO_WRITABLE : 0)
                        | (what != CURL_POLL_OUT ? IO_READABLE : 0);
            if (h) { io_poll_set(h, ev); }
            else   { h = io_poll_new(fd, ev, on_curl_ready, userp);  /* fd NOT owned */
                     curl_multi_assign(mh, fd, h); }
            break;
        }
        case CURL_POLL_REMOVE:
            if (h) io_close(h);   /* stop watching; fd stays open (cURL owns it) */
            break;
    }
    return 0;
}

static void on_curl_ready(io_handle_t *h, io_event_kind kind, io_slice_t *s, void *ud)
{
    if (kind == IO_EV_READY) {
        int running;
        curl_multi_socket_action(ud, io_handle_fd(h), to_curl_flags(s->events), &running);
    } else if (kind == IO_EV_CLOSE) {
        io_free(h);   /* memory released once the watcher is fully torn down */
    }
}
```

Because the watcher was created without `IO_F_OWNS_FD`, `io_close` only stops watching — it
does **not** `close()` the descriptor, which cURL still owns. `io_poll_new` is the reason the
immediate family exists.

---

## 6. Timers, signals, processes, filesystem

All follow the identical `(…, io_cb_t cb, void *user_data)` shape and deliver through the
unified callback. A periodic watcher keeps firing until `io_close`; there is no rearm return
value (control is explicit).

```c
/* Fire after `timeout_ms`. period_ms > 0 makes it periodic (fires every
 * period until io_close); 0 is one-shot. Delivers IO_EV_DONE on each fire. */
io_handle_t *io_timer_new(uint64_t timeout_ms, uint64_t period_ms,
                          io_cb_t cb, void *user_data);

/* Unix signal delivered on the loop thread. */
io_handle_t *io_signal_new(int signum, io_cb_t cb, void *user_data);

/* Child-process exit. slice->extra carries the exit code on IO_EV_DONE. */
io_handle_t *io_process_new(zend_process_t pid, io_cb_t cb, void *user_data);

/* Filesystem watcher. Fires IO_EV_DONE on rename/change; slice->extra points
 * at the changed path + event mask. Mirrors zend_async_new_filesystem_event. */
io_handle_t *io_fs_event(const char *path, unsigned flags,
                         io_cb_t cb, void *user_data);
```

```c
static void heartbeat(io_handle_t *h, io_event_kind kind, io_slice_t *s, void *ud)
{
    if (kind == IO_EV_DONE) send_ping(ud);   /* keeps firing; io_close(h) to stop */
    else if (kind == IO_EV_CLOSE) io_free(h);
}
```

Note how `io_timer_new`, `io_signal_new`, `io_process_new`, `io_fs_event` are all the same
call shape as `io_poll_new` and `io_read`. Learn the callback once, use it everywhere.

---

## 7. Trigger — cross-thread wakeup between event loops

`io_trigger` is the one primitive that is **safe to fire from another thread**. It is the
bridge between an event loop running on the main thread and work happening on worker
threads (a thread pool, a blocking DNS resolver, a native library with its own threads).
It corresponds to libuv's `uv_async_t` and to the existing `zend_async_trigger_event_t`.

```c
/* Create a trigger bound to THIS loop. cb runs ON THE LOOP THREAD when the
 * trigger is fired from anywhere. */
io_handle_t *io_trigger_new(io_cb_t cb, void *user_data);

/* Fire the trigger. THREAD-SAFE — the ONLY io_* call that may be invoked from
 * a thread other than the loop's owner. Coalescing: N fires before the loop
 * turns may collapse into one IO_EV_DONE (edge, not counted). Wakes the loop
 * if it is blocked in io_run(). */
int io_trigger_fire(io_handle_t *h);
```

Typical use — a worker thread finishes and wakes the coroutine that is waiting on it:

```c
/* --- worker thread --- */
result = do_blocking_work(job);
job->result = result;
io_trigger_fire(job->trigger);          /* safe cross-thread hop           */

/* --- loop thread --- */
static void on_worker_done(io_handle_t *h, io_event_kind kind, io_slice_t *s, void *ud)
{
    job_t *job = ud;
    if (kind == IO_EV_DONE) {
        /* Back on the loop thread: now it is safe to touch engine state and
         * hand the result to the scheduler (see §9). */
        ZEND_ASYNC_RESUME(job->waiter);
        io_close(h);
    } else if (kind == IO_EV_CLOSE) {
        io_free(h);
    }
}
```

Everything the reactor does is single-threaded *except* `io_trigger_fire`. That one crack
in the single-thread rule is what lets the thread pool, and any future off-loop producer,
deliver results back into the loop without locks in the hot path.

---

## 8. DNS — a separate group

DNS is deliberately its own family, not folded into the stream API, because a resolution is
a *request/response* with an owned result that must be explicitly freed — not a stream and
not a readiness event. It mirrors `getaddrinfo`/`getnameinfo` and the existing
`zend_async_getaddrinfo_fn` / `zend_async_getnameinfo_fn` slots.

```c
/* Forward resolution: node/service -> struct addrinfo list. On IO_EV_DONE,
 * slice->extra is a `struct addrinfo *` the caller MUST free with
 * io_dns_freeaddrinfo(). On IO_EV_ERROR, io_error(h) has domain IO_ERR_EAI. */
io_handle_t *io_dns_getaddrinfo(const char *node, const char *service,
                                const struct addrinfo *hints,
                                io_cb_t cb, void *user_data);

/* Reverse resolution: sockaddr -> host/service names. On IO_EV_DONE,
 * slice->extra points at a small { char *host; char *service; } owned by the
 * handle and freed at IO_EV_CLOSE. */
io_handle_t *io_dns_getnameinfo(const struct sockaddr *addr, int flags,
                                io_cb_t cb, void *user_data);

/* Release the addrinfo list handed to io_dns_getaddrinfo's callback. */
void io_dns_freeaddrinfo(struct addrinfo *ai);
```

```c
static void on_resolved(io_handle_t *h, io_event_kind kind, io_slice_t *s, void *ud)
{
    connect_ctx_t *ctx = ud;
    switch (kind) {
        case IO_EV_DONE: {
            struct addrinfo *ai = s->extra;
            ctx->fd = try_connect(ai);
            io_dns_freeaddrinfo(ai);          /* explicit — see note above    */
            ZEND_ASYNC_RESUME(ctx->waiter);
            io_close(h);
            break;
        }
        case IO_EV_ERROR:
            ctx->err = io_error(h);           /* domain == IO_ERR_EAI          */
            ZEND_ASYNC_RESUME(ctx->waiter);
            io_close(h);
            break;
        case IO_EV_CLOSE:
            io_free(h);
            break;
        default: break;
    }
}
```

The DNS group is where the "backend does the work on a thread pool, `io_trigger` delivers
the answer" pattern from §7 is used internally by most reactors: `getaddrinfo` blocks, so
libuv-style backends run it on the thread pool and hop the result back with a trigger. From
the caller's side it is just another `io_cb_t`.

---

## 9. Combining the reactor with the Scheduler

This is the whole point: the reactor and the scheduler are two halves of one loop. The
scheduler (scheduler_rfc.md) owns coroutines and a run queue; the reactor owns fds and
timers. They meet at exactly two points.

### 9.1 Point one — the reactor callback resumes a coroutine

Every `io_cb_t` that backs a coroutine-level operation does the same last thing: it calls
the scheduler's **RESUME** hook (exposed in C as `ZEND_ASYNC_RESUME`) to move the waiting
coroutine from *suspended* back into the run queue. The reactor never runs coroutine code
itself — it just flips the coroutine to *ready* and returns. The scheduler runs it on the
next turn.

#### The canonical example — `sleep($seconds)`

The simplest possible reactor↔scheduler interaction, and the shape every blocking builtin
follows. An async `sleep()` is *"arm a timer, suspend, resume from the timer callback"* —
three lines of choreography:

```c
/* What the coroutine waits on: just the coroutine to wake. */
typedef struct { zend_coroutine_t *co; } sleep_await_t;

/* 3. The timer ticked: the reactor calls us on the loop thread. Resume the
 *    coroutine and tear the one-shot timer down. */
static void sleep_wake(io_handle_t *h, io_event_kind kind, io_slice_t *s, void *ud)
{
    sleep_await_t *w = ud;
    if (kind == IO_EV_DONE) {          /* timer fired */
        ZEND_ASYNC_RESUME(w->co);      /* → scheduler enqueues the coroutine */
        io_close(h);                   /* one-shot: done with the timer      */
    } else if (kind == IO_EV_CLOSE) {
        io_free(h);                    /* timer memory released              */
    }
}

/* PHP: sleep($seconds) — called on the coroutine's stack. */
void php_async_sleep(zend_long seconds)
{
    sleep_await_t w = { ZEND_ASYNC_CURRENT_COROUTINE };

    /* 1. Create a one-shot timer (period = 0) for `seconds` seconds. */
    io_timer_new((uint64_t) seconds * 1000, /*period*/ 0, sleep_wake, &w);

    /* 2. Suspend: hand control to the scheduler. This call RETURNS only after
     *    sleep_wake() has resumed us — i.e. after the timer fired. While we are
     *    parked here, the scheduler runs other coroutines, and when none are
     *    runnable it blocks in io_run(), where the timer eventually ticks. */
    ZEND_ASYNC_SUSPEND();

    /* ...control returns here `seconds` later; sleep() is done. */
}
```

Walking the three steps against the two components:

| Step | In the coroutine (`php_async_sleep`) | In the scheduler / reactor |
|------|--------------------------------------|----------------------------|
| 1. arm | `io_timer_new(...)` registers a timer | reactor adds a timer to the loop |
| 2. suspend | `ZEND_ASYNC_SUSPEND()` | SUSPEND hook: no runnable coroutine → scheduler blocks in `io_run()` |
| — wait | (parked) | `io_run()` sleeps in the kernel until the timer is due |
| 3. resume | (still parked) | timer fires → `sleep_wake()` → `ZEND_ASYNC_RESUME()` → coroutine re-queued; `io_run()` returns; scheduler switches back in |
| done | `SUSPEND()` returns | — |

Every other blocking builtin — `usleep`, a socket read, `gethostbyname`, `stream_select` —
is the *same* three-step dance with a different `io_*` constructor in step 1. `sleep` just
happens to use `io_timer_new`. That uniformity is the payoff of the single `io_cb_t` shape.

#### A read, with a caller-provided buffer

The same pattern for I/O, adding the `IO_EV_ALLOC` phase (the caller owns the buffer, §4.1):

```c
/* A minimal adapter: suspend the current coroutine on a stream read. */
typedef struct { zend_coroutine_t *co; char *buf; size_t cap, n; } read_await_t;

static void read_resume(io_handle_t *h, io_event_kind kind, io_slice_t *s, void *ud)
{
    read_await_t *w = ud;
    switch (kind) {
        case IO_EV_ALLOC: s->base = w->buf; s->len = w->cap; break;   /* caller's buffer */
        case IO_EV_DATA:  w->n = s->len; ZEND_ASYNC_RESUME(w->co); io_close(h); break;
        case IO_EV_EOF:   w->n = 0;      ZEND_ASYNC_RESUME(w->co); io_close(h); break;
        case IO_EV_ERROR: ZEND_ASYNC_RESUME_WITH_ERROR(w->co,
                              io_make_exception(io_error(h)));      io_close(h); break;
        case IO_EV_CLOSE: io_free(h); break;
        default: break;
    }
}

ssize_t coro_read(io_handle_t *stream, char *buf, size_t cap)
{
    read_await_t w = { ZEND_ASYNC_CURRENT_COROUTINE, buf, cap, 0 };
    io_read(stream, read_resume, &w);
    ZEND_ASYNC_SUSPEND();      /* hands control to the scheduler; returns   */
    return w.n;                /* when read_resume() resumed us              */
}
```

`ZEND_ASYNC_SUSPEND()` routes through the scheduler's **SUSPEND** hook, which picks the next
coroutine or, if the queue is empty, blocks the thread inside the reactor (next point).
`ZEND_ASYNC_RESUME()` routes through the **RESUME** hook, which the scheduler_rfc example
implements as "enqueue the coroutine on the run queue". The error path uses RESUME's
`?Throwable $error` parameter — the reactor turns the `io_error_t` into an exception object
and the scheduler throws it at the coroutine's suspension point. This is precisely the "how
timeouts and IO failures reach waiting code" mechanism the RFC describes.

#### A core PHP function — `gethostbyname($host)`

The same three steps power a real userland builtin. Here is how the engine's
`gethostbyname()` becomes non-blocking without changing its PHP-visible signature: it maps
onto the DNS group (§8), suspends, and resumes from the resolver callback. Note the reason
being pulled from `io_error(h)` on failure — including a cancellation reason if the coroutine
was cancelled mid-lookup (§10).

```c
typedef struct {
    zend_coroutine_t *co;
    zend_string      *result;   /* resolved IP, or NULL on failure */
    zend_object      *error;    /* exception to throw, or NULL     */
} dns_await_t;

/* 3. Resolver finished (on the loop thread): fill the result, resume, tear down. */
static void gethostbyname_done(io_handle_t *h, io_event_kind kind, io_slice_t *s, void *ud)
{
    dns_await_t *w = ud;
    switch (kind) {
        case IO_EV_DONE: {                       /* success: extra = addrinfo list */
            struct addrinfo *ai = s->extra;
            char ip[INET6_ADDRSTRLEN];
            addrinfo_to_string(ai, ip, sizeof ip);
            w->result = zend_string_init(ip, strlen(ip), 0);
            io_dns_freeaddrinfo(ai);             /* explicit free (§8)             */
            ZEND_ASYNC_RESUME(w->co);
            io_close(h);
            break;
        }
        case IO_EV_ERROR:                        /* failure OR cancellation:       */
            w->error = io_make_exception(io_error(h));  /* reason from io_error(h) */
            ZEND_ASYNC_RESUME(w->co);
            io_close(h);
            break;
        case IO_EV_CLOSE:
            io_free(h);
            break;
        default: break;
    }
}

/* PHP: gethostbyname($host) — runs on the coroutine's stack. */
PHP_FUNCTION(gethostbyname)   /* sketch: arg parsing elided */
{
    zend_string *host = /* ... ZEND_PARSE_PARAMETERS ... */;

    dns_await_t w = { ZEND_ASYNC_CURRENT_COROUTINE, NULL, NULL };
    struct addrinfo hints = { .ai_family = AF_UNSPEC, .ai_socktype = SOCK_STREAM };

    /* 1. Arm the async resolution. Internally the reactor runs getaddrinfo on
     *    its thread pool and hops the answer back with an io_trigger (§7, §8). */
    io_dns_getaddrinfo(ZSTR_VAL(host), NULL, &hints, gethostbyname_done, &w);

    /* 2. Suspend until gethostbyname_done() resumes us. */
    ZEND_ASYNC_SUSPEND();

    if (w.error) {
        zend_throw_exception_object(w.error);   /* DNS failure or cancellation */
        RETURN_THROWS();
    }
    RETURN_STR(w.result);
}
```

From the PHP script's side nothing changed — `gethostbyname("php.net")` still returns a
string — but the whole time the lookup is in flight, the scheduler is free to run other
coroutines. The only reactor-specific code is the ~15-line callback; the rest is ordinary
extension boilerplate. Swap `io_dns_getaddrinfo` for `io_timer_new` and you have `sleep`;
swap it for `io_read` and you have `fread`. One shape, every blocking builtin.

### 9.2 Point two — the scheduler blocks in the reactor when idle

The scheduler's run loop and the reactor's poll are one interleaved loop. When the run queue
drains, there is nothing to switch *to* — so instead of spinning, the scheduler asks the
reactor to block until the next OS event. In the RFC's terms this happens inside the
`suspend` hook; in C the reactor exposes the loop step:

```c
bool io_run(bool no_wait);   /* one loop turn: poll (block unless no_wait),
                                fire ready callbacks, return true if any handle
                                is still armed (the loop should keep going).   */
bool io_alive(void);         /* are there armed handles? (loop-keepalive)      */
void io_stop(void);          /* request the loop to return at the next turn.   */
uint64_t io_now(void);       /* cached loop time in ms (no syscall).            */
```

The combined loop, written from the scheduler's side:

```c
/* Scheduler's idle step — the SUSPEND hook falls into this when the run
 * queue is empty but coroutines are still alive somewhere in the reactor. */
while (scheduler_has_live_coroutines()) {
    if (run_queue_empty()) {
        io_run(/*no_wait=*/false);   /* BLOCK here until an fd/timer fires.   */
        /* fired io_cb_t's have called ZEND_ASYNC_RESUME → run queue refilled */
    }
    zend_coroutine_t *co = run_queue_dequeue();
    coroutine_switch_to(co);         /* engine performs the actual switch     */
}
```

The two components hand control back and forth: coroutines run until they all park on IO →
the run queue empties → the scheduler blocks in `io_run()` → an OS event fires an `io_cb_t`
→ the callback calls `ZEND_ASYNC_RESUME` → `io_run()` returns with a non-empty run queue →
the scheduler switches into the woken coroutine. No coroutine is ever lost, and the thread
never busy-waits. This is the same lifecycle the RFC's *"After main → remaining coroutines
drain"* row describes, with the reactor supplying the events that let them drain.

### 9.3 Where each responsibility lives

| Concern                          | Owner       | Mechanism                                   |
|----------------------------------|-------------|---------------------------------------------|
| Which coroutine runs next        | Scheduler   | `suspend` / `enqueue` hooks (RFC)           |
| Performing the context switch    | Engine      | Fiber switch (RFC "mechanism")              |
| When an fd/timer/signal is ready | **Reactor** | `io_*` callbacks (this document)            |
| Waking a coroutine on an event   | Both        | reactor callback → `ZEND_ASYNC_RESUME` hook |
| Blocking the thread when idle    | **Reactor** | `io_run(no_wait=false)`                      |
| Cross-thread result delivery     | **Reactor** | `io_trigger_fire` → callback → RESUME       |

The reactor knows nothing about coroutines, fibers, or the run queue — it only knows fds and
callbacks. The scheduler knows nothing about epoll or timers — it only knows the run queue
and the `io_run` step. The `io_cb_t` bodies (the adapters in §9.1) are the *only* code that
touches both, and they are tiny. That thin seam is what keeps the reactor reusable (a
userland event loop can drive the same `io_*` slots) and the scheduler pluggable (a pure-PHP
scheduler from the RFC can sit on top of any registered reactor).

---

## 10. Lifetime: cancel vs. close vs. free

Teardown is **three distinct operations at three levels**, not one. Conflating them is the
usual source of use-after-free and "the fd closed but I still had a pending write" bugs.

```c
/* 1. CANCEL the in-flight operation. The handle STAYS OPEN and reusable.
 *    The canceller MUST supply a reason; the cancelled op's callback fires
 *    IO_EV_ERROR and io_error(h) returns exactly that reason (domain/code/data),
 *    so the waiter learns WHY it was cancelled — a timeout, a parent cancelling
 *    a child, a losing racer — not just a bare -ECANCELED. Pass {IO_ERR_URING,
 *    -ECANCELED, NULL} for a plain cancel. Use for timeouts / racing two ops. */
void io_cancel(io_handle_t *h, io_error_t reason);

/* 2. CLOSE the resource (fd/socket) and stop the watcher. ASYNCHRONOUS: an
 *    operation may be in flight, so the reactor drains it first — any bytes the
 *    kernel already read are delivered as a final IO_EV_DATA — then closes the
 *    fd if IO_F_OWNS_FD, and finally delivers exactly one IO_EV_CLOSE on the
 *    loop thread. After close the handle is dead as a resource, but its MEMORY
 *    may still be alive if refs remain. */
void io_close(io_handle_t *h);

/* 3. Memory management by reference count. io_free drops the caller's primary
 *    reference; io_ref/io_unref bracket any extra owner. The memory is
 *    actually released when the count reaches zero — which is guaranteed to be
 *    no earlier than IO_EV_CLOSE and never while the kernel still owns an
 *    in-flight request. Safe to call io_free from inside IO_EV_CLOSE. */
io_handle_t *io_ref(io_handle_t *h);
void         io_unref(io_handle_t *h);
void         io_free(io_handle_t *h);
```

### 10.1 Why the split — resource vs. memory

`io_close` is about the **resource**: the OS descriptor, the watcher registration, the
kernel-side request. `io_free` is about the **memory**: the `io_handle_t` allocation itself.
They are separate because a closed handle can still be *referenced* — by an in-flight kernel
completion that has not fired yet, or by another part of the program that took an `io_ref`.
Freeing the memory the instant you close the resource is a use-after-free: `free(h)`, then
the io_uring CQE arrives and the kernel's completion path dereferences freed memory.

This is exactly the libuv pattern (`uv_close(handle, cb)` → free the memory in `cb`) and the
`zend_async_API.h` pattern (a separate `close`/`dispose` split guarded by `ref_count`). The
canonical flow — note the drained `IO_EV_DATA`: because `io_close` is asynchronous, bytes the
kernel had already read are delivered before the close rather than thrown away:

```
io_close(h) ─► drain kernel ─► [final IO_EV_DATA] ─► close fd ─► IO_EV_CLOSE ─► io_free(h)
                              (already-read bytes)              (last callback)  (memory gone)
```

### 10.2 The refcount rules

The handle starts life with **one** reference — the one the constructor returns. The reactor
also holds an *internal* reference for **each operation outstanding in the kernel**:

```
refcount(h) = (owner refs: 1 from _new, +N from io_ref)
            + (in-flight refs: 1 per submitted-but-not-yet-completed backend op)
```

- Submitting a backend op (an io_uring SQE, a thread-pool DNS job) takes an internal ref.
- Its completion (CQE, trigger) drops that internal ref *after* invoking the callback.
- `io_close` flags the handle "dying", cancels the in-flight op, and refuses new operations.
- `io_free` drops the owner's ref.
- When the count reaches zero, the handle is off the loop, off the kernel and unreachable —
  the reactor emits `IO_EV_CLOSE` (if not already) and releases the memory. Exactly once.

That the *in-flight completion* is a first-class reference is what makes destruction safe
under io_uring, where "the kernel still owns this request" is a routine state rather than an
edge case (§14, decisions 8; mechanics in §11).

### 10.3 Why `io_close`/`io_free` never block

Both return immediately even when a completion is pending. They must — they can be called
from inside a callback, from a coroutine about to suspend, or during request shutdown where
blocking is forbidden. The contract is *"you will get exactly one `IO_EV_CLOSE` when it is
safe; until then, don't touch `h`"*. Owners that need to know when teardown finished (e.g.
to free a parent object) do it in the `IO_EV_CLOSE` branch of the same unified callback.

> **Threads note.** The thread/worker-pool surface (`spawn`, thread-local transfer, the
> `zend_async_thread_*` slots) is intentionally **out of scope here** and belongs in a
> separate Thread API document. The reactor only exposes the single cross-thread crack it
> needs — `io_trigger` (§7) — and treats everything a worker produces as data arriving
> through a trigger. The refcount rules above are what make that safe: a worker thread can
> hold an `io_ref` on the trigger handle for the duration of the job.

---

## 11. Sketch: implementing `io_*` on io_uring

The immediate API was shaped with io_uring in mind — "handle passed to every callback",
"caller-provided buffers", and "refcount pins the request until completion" all map onto the
submit/complete ring cleanly. This is a rough sketch, not a complete backend.

### 11.1 The request struct is the SQE's `user_data`

Every submitted operation carries a small request struct. Its address is stored in the SQE's
64-bit `user_data`; the CQE hands it straight back. The request pins the handle:

```c
typedef struct {
    io_handle_t   *h;        /* holds an in-flight ref on this handle       */
    io_event_kind  on_ok;    /* what kind to deliver on success             */
    struct iovec   iov;      /* for read/write                              */
} io_uring_req_t;

static void submit(io_handle_t *h, io_uring_req_t *r) {
    io_ref(h);                                  /* in-flight ref (§10.2)      */
    struct io_uring_sqe *sqe = io_uring_get_sqe(&ring);
    /* ... prep the op onto sqe ... */
    io_uring_sqe_set_data(sqe, r);              /* r travels to the CQE       */
}
```

### 11.2 One completion loop drives every unified callback

`io_run(no_wait)` *is* the CQE reap loop. It blocks in `io_uring_submit_and_wait`, then walks
the completions, and for each one reconstructs an `io_slice_t` and calls the handle's unified
callback — the same dispatch for reads, writes, timers, polls and triggers. For a read it
first asks the callback for a buffer (`IO_EV_ALLOC`) and submits *that* to the kernel:

```c
bool io_run(bool no_wait) {
    io_uring_submit_and_wait(&ring, no_wait ? 0 : 1);

    struct io_uring_cqe *cqe; unsigned head; unsigned n = 0;
    io_uring_for_each_cqe(&ring, head, cqe) {
        io_uring_req_t *r = io_uring_cqe_get_data(cqe);
        io_handle_t    *h = r->h;

        io_slice_t s = {0};
        io_event_kind kind;
        if (cqe->res < 0)        { set_error(h, IO_ERR_ERRNO, cqe->res); kind = IO_EV_ERROR; }
        else if (cqe->res == 0 &&
                 r->on_ok == IO_EV_DATA) { kind = IO_EV_EOF; }
        else                     { kind = r->on_ok;
                                   s.base = r->iov.iov_base; s.len = cqe->res; }

        h->cb(h, kind, &s, io_handle_data(h));      /* THE callback (void)      */

        bool multishot = (cqe->flags & IORING_CQE_F_MORE);
        if (!multishot) {
            io_unref(h);                 /* drop the in-flight ref (§10.2)      */
            if (!dying(h)) rearm_read(h, r);   /* ask IO_EV_ALLOC, submit again */
            else           free(r);
        }
        n++;
    }
    io_uring_cq_advance(&ring, n);
    return io_alive();
}
```

**Multishot lives entirely here.** The `IORING_CQE_F_MORE` branch is the *only* place the
word appears; the API above it never mentions it. On a kernel with multishot the backend arms
one `IORING_OP_RECV_MULTISHOT` and gets a stream of CQEs; on an old kernel or epoll it
re-submits per chunk. Same `io_read`, same `IO_EV_DATA` stream — the caller cannot tell
(§14, decision 7).

### 11.3 Op-by-op mapping

| `io_*` call            | io_uring op                                   | Notes                                             |
|------------------------|-----------------------------------------------|---------------------------------------------------|
| `io_poll_new`          | `IORING_OP_POLL_ADD` (+ `IORING_POLL_ADD_MULTI`) | Multishot poll = stays armed; one SQE, many CQEs. |
| `io_read`              | `IORING_OP_RECV`/`READ` (multishot + provided buffers) | Buffer comes from `IO_EV_ALLOC`; kernel fills it, emits `IO_EV_DATA` per CQE. `cqe->res==0` → `IO_EV_EOF`. |
| `io_write` / `io_writev` | `IORING_OP_SEND`/`WRITE`/`WRITEV`           | Caller's buffer; `IO_EV_WROTE` on its CQE; `free_cb` released there. |
| `io_timer_new`         | `IORING_OP_TIMEOUT` (`IORING_TIMEOUT_MULTISHOT`) | Periodic = multishot timeout; one-shot otherwise. |
| `io_signal_new`        | `signalfd` + `IORING_OP_POLL_ADD`             | Or `IORING_OP_POLL` on the signalfd.              |
| `io_process_new`       | `pidfd_open` + `IORING_OP_POLL_ADD`           | pidfd readable ⇒ child exited.                    |
| `io_trigger_new/_fire` | `eventfd` + poll, **or** `IORING_OP_MSG_RING` | `io_trigger_fire` = `write(eventfd)` / post to the target ring — the only cross-thread path. |
| `io_dns_getaddrinfo`   | thread-pool job → `io_trigger_fire`           | `getaddrinfo` blocks; run it off-ring, hop the result back through a trigger (§7). |
| `io_cancel`            | `IORING_OP_ASYNC_CANCEL`                      | Cancels the op; its CQE (`-ECANCELED`) fires `IO_EV_ERROR`, handle stays open. |
| `io_close`             | `ASYNC_CANCEL` + `close(fd)` after drain      | Cancel in-flight, close fd if owned, then `IO_EV_CLOSE`. |
| `io_free`              | (no SQE) memory release at refcount zero      | The last in-flight CQE drops the final ref → free. |

The two hard problems — **lifetime** and **cancellation** — fall out of the refcount model:
`io_close`/`io_cancel` submit an `ASYNC_CANCEL`, the cancelled op still produces a CQE, that
CQE drops the in-flight ref exactly like a normal completion, and only then does the handle
reach zero and free. The kernel never writes into freed memory because the request struct
outlives every SQE that references it, pinned by the in-flight ref.

---

## 12. Known hazards and how the model addresses them

A frank look at where a callback-first, single-handler reactor can go wrong.

1. **Use-after-free on in-flight completion (io_uring).** The classic ring footgun: free a
   request while a CQE is still coming. *Addressed* by the in-flight reference (§10.2) — the
   handle cannot reach zero while the kernel owns a request. This is the single most
   important reason the immediate API carries a refcount at all.

2. **Confusing close with free.** Calling `io_free` on a handle whose write is still in the
   kernel, expecting the fd to close and the write to vanish. *Addressed* by the three-way
   split (§10): `io_close` closes the resource and drains in-flight work; `io_free` only
   releases memory, gated by refcount, and never before `IO_EV_CLOSE`.

3. **Reentrancy: tearing the handle down inside its own callback.** A handler that calls
   `io_close(h)`/`io_free(h)` and then keeps using `h`. *Addressed* by: `io_close` only flags
   dying and starts async teardown — the struct stays valid until the current callback
   returns; `IO_EV_CLOSE` is always a *later, separate* delivery, never nested.

4. **The `IO_EV_DATA` buffer is the caller's — but only until when?** Because the caller
   allocates the read buffer (`IO_EV_ALLOC`), retaining bytes is trivial (don't reset the
   cursor). The hazard flips: the caller must not free that buffer while a read is armed, or
   the kernel writes into freed memory. *Mitigation*: the buffer's lifetime is tied to the
   handle; release it in `IO_EV_CLOSE`.

5. **`io_trigger` coalescing surprises.** N `io_trigger_fire` calls can collapse into one
   `IO_EV_DONE` (edge semantics, like `uv_async`). Code that assumes one callback per fire
   loses wakeups. *Mitigation*: the trigger carries no count; the handler drains a
   thread-safe queue until empty (§7).

6. **Cross-thread rule violations.** Every `io_*` call except `io_trigger_fire` is
   loop-thread-only. A worker thread that calls `io_read`/`io_close` directly corrupts the
   ring. *Mitigation*: a hard invariant; the Thread API document (out of scope here) must
   funnel *everything* back through `io_trigger`.

7. **Refcount leaks.** Every `io_ref` needs a matching `io_unref`; a handler that takes a ref
   and forgets it on an error path leaks the handle and its fd forever. *Mitigation*: keep
   `io_ref` rare — most handlers never need it because the handle is already passed to every
   callback (§3.1); refs are only for stashing a handle *outside* the callback.

8. **Data the kernel already read, lost on cancel.** `io_close` cancels an in-flight read
   that the kernel may have already partially filled. *Addressed* (§14, decision 9): because
   `io_close` is asynchronous it drains first — those bytes are delivered as a final
   `IO_EV_DATA` before `IO_EV_CLOSE`, never dropped.

9. **Head-of-line blocking on the loop thread.** A slow callback (synchronous compression,
   a blocking `getaddrinfo`) stalls the whole loop. *Mitigation*: none at the reactor level —
   offload heavy work to the Thread API and feed results back via `io_trigger`.

---

## 13. Relationship to the existing `zend_async_API.h`

This proposal does **not** replace the current reactor slots. It reframes them:

- The existing **event objects** (`zend_async_poll_event_t`, `zend_async_timer_event_t`, …)
  remain the substrate for engine primitives that need multiple listeners, replay, and
  `Awaitable` bridging. Under this proposal an event object is *"a handle whose unified
  callback fans out to a `zend_async_callbacks_vector_t`"* — i.e. the retained API becomes a
  thin layer over the immediate API.
- The **registration** path (`zend_async_reactor_register`) is unchanged in spirit: the
  `io_*` slots would be registered the same way, from the same reactor extension, at MINIT.
- The **immediate `io_*` family** is the new surface extensions and foreign libraries (cURL,
  TLS stacks, database drivers) should target. It is smaller, allocation-lean, and foreign-fd
  friendly.

Concretely, the mapping is nearly one-to-one:

| Immediate (`io_*`, proposed)     | Retained (`zend_async_API.h`, today)          |
|----------------------------------|-----------------------------------------------|
| `io_poll_new`                    | `zend_async_new_poll_event_fn` + add_callback |
| `io_read`                        | `zend_async_io_read_fn` + `alloc_cb`          |
| `io_write` / `io_writev`         | `zend_async_io_write_fn` / `io_writev_fn`     |
| `io_timer_new`                   | `zend_async_new_timer_event_fn`                |
| `io_signal_new`                  | `zend_async_new_signal_event_fn`               |
| `io_process_new`                 | `zend_async_new_process_event_fn`              |
| `io_fs_event`                    | `zend_async_new_filesystem_event_fn`           |
| `io_trigger_new/_fire`           | `zend_async_new_trigger_event_fn`              |
| `io_dns_getaddrinfo/getnameinfo` | `zend_async_getaddrinfo_fn/getnameinfo_fn`     |
| `io_run` / `io_alive`            | `zend_async_reactor_execute_fn/loop_alive_fn`  |
| `io_cancel` / `io_close`         | `zend_async_io_close_fn` + event `stop`        |
| `io_ref` / `io_unref` / `io_free`| `ZEND_ASYNC_EVENT_ADD_REF` / `..._RELEASE` / `dispose` |

**One place where the immediate API is deliberately *not* one-to-one: lifetime.** The
retained API's `ref_count` + `dispose()` assumes teardown happens once an event's callbacks
have settled. The immediate API splits teardown three ways — `io_cancel` (op),
`io_close` (resource), `io_free` (memory) — and promotes *"a completion is still in flight in
the kernel"* to a first-class reference (§10.2). This is the single biggest divergence, and
it exists because io_uring makes kernel-owned-request the common case rather than an edge
case. If the two families are ever unified, this is the point that needs the most care.

The **Thread / worker-pool** surface (`zend_async_thread_*`, snapshot transfer, thread-local
zval copy) is **not** covered by this document — it is a separate concern with its own
document. The reactor deliberately touches threads only through `io_trigger` (§7).

---

## 14. Design decisions (resolved) and open items

Decisions taken while drafting; each is reflected in the sections above.

1. **Read buffers are caller-provided.** The reactor asks the callback for a destination
   buffer via the `IO_EV_ALLOC` phase, then reports arrival via `IO_EV_DATA` — the cURL
   model. No reactor-owned scratch, no copy to retain bytes. (§3, §4.1)
2. **Control is explicit; the callback returns `void`.** Steering is done with functions
   (`io_pause`/`io_resume`/`io_cancel`/`io_close`/`io_free`), never a return value. (§3.2)
3. **Errors are pulled, not pushed.** `IO_EV_ERROR` fires; the handler calls `io_error(h)`,
   which returns a typed `io_error_t { domain; code; data }` so DNS/TLS/errno stay
   distinguishable. A separate custom-data slot (`io_set_udata`/`io_get_udata`) sits on the
   handle beside `user_data`. (§3.1, §3.3)
4. **`io_write` pushes a caller buffer** to a file or socket, with an optional `free_cb` for
   fire-and-forget — mirroring `zend_async_io_write_fn`. A pull/`IO_EV_DRAIN` streaming
   writer is an optional extension, not the default. (§4.2)
5. **Timeouts are a per-handle call** (`io_set_timeout(h, ms)`), not an argument on every
   operation. (§3.2)
6. **State goes in `user_data`** (plus the custom-data slot); no `extra_size` inline
   over-allocation. (§3.1)
7. **Multishot is the backend's private decision**, never surfaced in the ABI. `io_read` is
   "one call, a stream of `IO_EV_DATA`" regardless of whether the backend uses io_uring
   multishot or per-chunk resubmission. (§4.1, §11.2)
8. **Teardown is three operations:** `io_cancel` (cancel the op, keep the handle),
   `io_close` (close the resource, async, → `IO_EV_CLOSE`), `io_free` (release memory, by
   refcount). Resource and memory are separate concerns. (§10)
9. **`io_close` drains before closing.** Being asynchronous, `io_close` delivers any bytes
   the kernel already read as a final `IO_EV_DATA`, then `IO_EV_CLOSE` — no lost data. (§10.1)

**Still open:**

- **A. Exact `io_error_t` domains and codes.** The enum in §3.3 is a starting set; TLS and
  backend-specific domains need pinning down before an ABI freeze.

---

## Out of scope (separate documents)

- **Thread / worker-pool API.** `spawn`, thread-local zval transfer, snapshots and the
  `zend_async_thread_*` slots are a separate concern with their own design document. The
  reactor touches threads only through `io_trigger` (§7), and the refcount rules (§10) are
  what make a worker holding a handle safe.
- **The Scheduler itself.** Coroutine policy, hooks and registration live in
  [scheduler_rfc.md](scheduler_rfc.md); this document only shows the join (§9).

## References

- [scheduler_rfc.md](scheduler_rfc.md) — the Scheduler Hook API this reactor feeds.
- [SCHEDULER.md](SCHEDULER.md) — exact engine invocation points for the scheduler.
- `Zend/zend_async_API.h` — the existing retained-mode reactor slots and registration.
- libuv `uv_poll_t`, `uv_async_t`, `uv_getaddrinfo_t`, `uv_close` — the closest existing-art
  for `io_poll_new`, `io_trigger`, the DNS group, and the close/free split.
- Linux `io_uring` — `IORING_OP_POLL_ADD` (multishot), `IORING_OP_RECV`/`SEND`,
  `IORING_OP_TIMEOUT`, `IORING_OP_MSG_RING`, `IORING_OP_ASYNC_CANCEL` (§11).
