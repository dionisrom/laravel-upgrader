# P1-17-03: Close dead streams in EventBus::broadcast()

**Source:** P1-17 review  
**Severity:** LOW  
**Requirements:** TRD-DASH-003

## Problem

When a write fails in `broadcast()`, the client is unset but the stream is not explicitly closed.

## Fix

- Call `$stream->close()` in the catch block before unsetting.
- Add test assertion that dead client is removed from client list after broadcast.
