<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap that loads the main Composer autoloader first,
 * then prevents rector's bundled (un-scoped) nikic/php-parser from
 * colliding with the root copy.
 *
 * Rector's preload.php skips loading its bundled php-parser when it
 * detects that PHPStan's test case is loaded AND the PhpParser\Node
 * interface already exists. We satisfy both conditions here so the
 * preload guard triggers and rector's duplicate classes are never loaded.
 */

require_once __DIR__ . '/vendor/autoload.php';

// Ensure the root PhpParser\Node interface is loaded
if (!interface_exists(\PhpParser\Node::class, false)) {
    class_exists(\PhpParser\Node::class, true);
}

// Load PHPStanTestCase so rector's preload.php guard evaluates to true
// and skips loading its bundled php-parser classes.
if (!class_exists(\PHPStan\Testing\PHPStanTestCase::class, false)) {
    class_exists(\PHPStan\Testing\PHPStanTestCase::class, true);
}
