# P1-17: ReactPHP Dashboard Server

**Phase:** 1 — MVP  
**Priority:** Must Have  
**Estimated Effort:** 6-7 days  
**Dependencies:** P1-01 (Project Scaffold), P1-11 (Event Streaming — provides events to broadcast)  
**Blocks:** P1-19 (CLI — DashboardCommand)  

---

## Agent Persona

**Role:** ReactPHP/Real-time Systems Engineer  
**Agent File:** `agents/reactphp-dashboard-engineer.agent.md`  
**Domain Knowledge Required:**
- ReactPHP event loop, HTTP server, and socket server
- Server-Sent Events (SSE) protocol and connection management
- Non-blocking I/O patterns in PHP
- Single-page application design with vanilla JS (no framework)
- Tailwind CSS (CDN)
- Browser EventSource API with reconnection logic
- Understanding of why PHP built-in server is unsuitable (F-02 — single-threaded deadlock)

---

## Objective

Implement `ReactDashboardServer.php`, `EventBus.php`, and the `public/index.html` SPA dashboard. The dashboard provides real-time upgrade monitoring via SSE to the browser, handles concurrent connections, and auto-opens in the user's browser.

---

## Context from PRD & TRD

### CRITICAL — F-02: PHP Built-in Server Deadlocks SSE

> PHP's `php -S` is single-threaded. One SSE connection blocks all other HTTP requests. ReactPHP handles hundreds of concurrent SSE connections correctly.

### ReactDashboardServer (TRD §12.1 — TRD-DASH-001 through TRD-DASH-005)

```php
final class ReactDashboardServer
{
    private array $sseClients = [];  // string $id => ThroughStream $stream

    public function start(int $port = 8765): void;
    public function broadcast(array $event): void;
    public function stop(): void;

    // Routes:
    // GET /        → serves public/index.html
    // GET /events  → SSE endpoint
    // GET /static/* → serves assets
}
```

**SSE Endpoint (TRD-DASH-003):**
1. Headers: `Content-Type: text/event-stream`, `Cache-Control: no-cache`, `X-Accel-Buffering: no`
2. Create `ThroughStream`, add to `$sseClients` with unique ID
3. `close` listener removes client from `$sseClients`
4. Initial heartbeat: `data: {"event":"connected"}\n\n`

**Broadcast (TRD-DASH-004):**
Catch `\Throwable` per client. Dead clients removed silently. One client failure doesn't affect others.

**Auto-Open (TRD-DASH-005):**
Use `xdg-open` (Linux), `open` (macOS), `start` (Windows/WSL2).

### Dashboard Frontend (TRD §12.2 — TRD-DASH-006 through TRD-DASH-008)

**TRD-DASH-006:** `public/index.html` is self-contained. ALL CSS/JS inline or Tailwind CDN. No build toolchain.

**TRD-DASH-007:** SSE client with exponential backoff reconnection (initial 1s, max 30s).

**TRD-DASH-008:** Dashboard panels:
- Overall progress bar with `{completedHops}/{totalHops}` + elapsed time
- Current hop name and pipeline stage
- Per-stage status icons: ⏳ pending / 🔄 running / ✅ done / ❌ failed
- Live scrolling log panel (last 100 entries, auto-scroll, pause on hover)
- Breaking changes tracker (rule name / AUTO or MANUAL / file count)
- Summary counters: Files Changed, Warnings, Errors, Manual Review Required

### PRD Requirements

| ID | Requirement |
|---|---|
| DB-01 | ReactPHP non-blocking on localhost:8765 |
| DB-02 | Concurrent SSE without blocking |
| DB-03 | Client disconnect detection |
| DB-04 | Auto-open browser |
| DB-05 | Progress bar with % and ETA |
| DB-06 | Per-stage pipeline status |
| DB-07 | Live scrolling log panel |
| DB-08 | Breaking changes tracker |
| DB-09 | Summary counters |
| DB-10 | Lumen migration sub-view |
| DB-11 | Tailwind CDN, no build step |

### Performance (TRD-PERF-002)

Dashboard MUST be reachable (HTTP 200 on `/`) within 5 seconds of `upgrader run`.

---

## Files to Create

| File | Location | Purpose |
|---|---|---|
| `ReactDashboardServer.php` | `src/Dashboard/` | ReactPHP HTTP+SSE server |
| `EventBus.php` | `src/Dashboard/` | Event routing to SSE broadcast |
| `index.html` | `src/Dashboard/public/` | Self-contained SPA dashboard |

---

## Acceptance Criteria

- [ ] ReactPHP server starts on `localhost:8765` (configurable port)
- [ ] SSE connections handled concurrently — 50+ simultaneous connections (DB-02)
- [ ] Client disconnect detected and stream closed cleanly (DB-03)
- [ ] Browser auto-opens on upgrade start (xdg-open/open/start)
- [ ] Dashboard reachable (HTTP 200) within 5 seconds of start
- [ ] Overall progress bar with percentage, hop count, and elapsed time
- [ ] Per-stage pipeline status with status icons
- [ ] Live scrolling log panel (last 100 entries, auto-scroll, pause on hover)
- [ ] Breaking changes tracker (auto/manual, file counts)
- [ ] Summary counters for files changed, warnings, errors, manual review
- [ ] Lumen migration sub-view when Lumen detected (DB-10)
- [ ] Tailwind CDN styling, no build step (DB-11)
- [ ] SSE reconnection with exponential backoff (1s→30s)
- [ ] `public/index.html` is self-contained — all JS inline
- [ ] `broadcast()` failure for one client doesn't affect others

---

## Implementation Notes

- ReactPHP deps: `react/http ^1.9`, `react/socket ^1.14`, `react/event-loop ^1.3`
- Use `React\Http\Message\Response` for SSE with streaming body
- `ThroughStream` from `react/stream` for per-client SSE streams
- The EventBus bridges between `EventStreamer` (P1-10) and `ReactDashboardServer`
- Keep the frontend simple — vanilla JS EventSource, DOM manipulation, Tailwind classes
- Consider a heartbeat mechanism (periodic ping to detect stale clients)
- The dashboard is host-side only — it does NOT run inside Docker containers
