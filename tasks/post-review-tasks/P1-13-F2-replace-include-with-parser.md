# P1-13-F2: Replace `include` with nikic/php-parser for Config Loading

**Severity:** High  
**Requirement:** Implementation Notes ("Use nikic/php-parser to safely parse PHP config array files (don't use include)")  
**Finding:** `ConfigMigrator::loadConfigFile()` uses PHP `include` to evaluate user config files, risking arbitrary code execution.

## Fix

Replace `loadConfigFile()` with a nikic/php-parser based implementation that:
1. Parses the PHP AST without executing code
2. Extracts the returned array from the `return` statement
3. Evaluates static array values (strings, ints, booleans, env() calls → default values)
4. Falls back gracefully for dynamic expressions (marks as unparseable)

Remove `config-stubs.php` and `ensureHelperStubs()` since they become unnecessary.
