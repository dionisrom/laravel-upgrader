# P1-18-F6: MarkdownFormatter heredoc produces leading whitespace

**Severity:** LOW  
**Files:** `src-container/Report/Formatters/MarkdownFormatter.php`

## Problem

`buildHeader()` and `renderItem()` use indented heredoc syntax causing leading spaces in every output line. This may break Markdown heading/code rendering.

## Required Fix

1. Remove leading indentation from heredoc content or use non-indented heredoc blocks
