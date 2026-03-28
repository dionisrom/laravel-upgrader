# P1-19-F4: Missing Tests for AnalyseCommand, DashboardCommand, VersionCommand

**Severity:** High  
**Source:** P1-19 review  
**Requirement:** All four commands are acceptance criteria deliverables

## Problem

Only `RunCommandTest` exists. Three of four commands have zero test coverage.

## Fix

Create:
- `AnalyseCommandTest.php` — validates command name, options, input validation, dry-run behavior
- `DashboardCommandTest.php` — validates command name, options, port validation
- `VersionCommandTest.php` — validates output contains version string
