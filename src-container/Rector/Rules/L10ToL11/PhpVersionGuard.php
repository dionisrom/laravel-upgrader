<?php

declare(strict_types=1);

namespace AppContainer\Rector\Rules\L10ToL11;

/**
 * PHP Version Guard for Laravel 11 hop.
 *
 * This is NOT a Rector rule — it is a standalone CLI script run as a pipeline
 * stage before Rector in entrypoint.sh. It reads the workspace composer.json
 * and checks whether the declared PHP constraint satisfies >= 8.2.
 *
 * Emits a JSON-ND warning to stdout if the project requires PHP < 8.2.
 * This feeds into the Phase 3 2D HopPlanner which interleaves PHP upgrades.
 *
 * Usage: php PhpVersionGuard.php /path/to/composer.json
 *
 * @see \Tests\Unit\Rector\Rules\L10ToL11\PhpVersionGuardTest
 */
final class PhpVersionGuard
{
    private const PHP_MINIMUM = '8.2';
    private const PHP_MINIMUM_MAJOR = 8;
    private const PHP_MINIMUM_MINOR = 2;

    /**
     * Check the workspace composer.json PHP constraint.
     *
     * @return array{status: string, message: string, declared_constraint: string|null, php_minimum: string}
     */
    public function check(string $composerJsonPath): array
    {
        if (! file_exists($composerJsonPath)) {
            return [
                'status' => 'error',
                'message' => 'composer.json not found at: ' . $composerJsonPath,
                'declared_constraint' => null,
                'php_minimum' => self::PHP_MINIMUM,
            ];
        }

        $content = file_get_contents($composerJsonPath);
        if ($content === false) {
            return [
                'status' => 'error',
                'message' => 'Cannot read composer.json at: ' . $composerJsonPath,
                'declared_constraint' => null,
                'php_minimum' => self::PHP_MINIMUM,
            ];
        }

        $decoded = json_decode($content, true);
        if (! is_array($decoded)) {
            return [
                'status' => 'error',
                'message' => 'Invalid JSON in composer.json',
                'declared_constraint' => null,
                'php_minimum' => self::PHP_MINIMUM,
            ];
        }

        $phpConstraint = $decoded['require']['php'] ?? null;
        if (! is_string($phpConstraint)) {
            return [
                'status' => 'warning',
                'message' => 'No PHP version constraint declared in composer.json. Verify PHP >= 8.2 is available.',
                'declared_constraint' => null,
                'php_minimum' => self::PHP_MINIMUM,
            ];
        }

        if ($this->satisfiesMinimum($phpConstraint)) {
            return [
                'status' => 'ok',
                'message' => 'PHP constraint ' . $phpConstraint . ' satisfies Laravel 11 minimum (PHP 8.2).',
                'declared_constraint' => $phpConstraint,
                'php_minimum' => self::PHP_MINIMUM,
            ];
        }

        return [
            'status' => 'warning',
            'message' => 'PHP constraint "' . $phpConstraint . '" does not satisfy Laravel 11 minimum (PHP 8.2). '
                . 'A PHP version upgrade hop must run before or alongside this Laravel hop. '
                . 'The 2D HopPlanner (Phase 3) handles PHP+Laravel interleaving.',
            'declared_constraint' => $phpConstraint,
            'php_minimum' => self::PHP_MINIMUM,
        ];
    }

    /**
     * Check whether a composer PHP constraint satisfies PHP >= 8.2.
     *
     * Handles common constraint formats: ^8.2, >=8.2, ~8.2, 8.2.*, ^8.1 (fail), etc.
     * This is a best-effort check — complex OR/AND constraints default to warning.
     */
    public function satisfiesMinimum(string $constraint): bool
    {
        // Normalize whitespace
        $constraint = trim($constraint);

        // Strip leading caret, tilde, >=, ~, etc.
        // Extract the first version number mentioned
        if (preg_match('/[~^>=]*\s*(\d+)\.(\d+)/', $constraint, $matches)) {
            $major = (int) $matches[1];
            $minor = (int) $matches[2];

            if ($major > self::PHP_MINIMUM_MAJOR) {
                return true;
            }

            if ($major === self::PHP_MINIMUM_MAJOR && $minor >= self::PHP_MINIMUM_MINOR) {
                return true;
            }

            return false;
        }

        // Cannot parse — conservative: return false (trigger warning)
        return false;
    }
}

// ─── CLI entry point ──────────────────────────────────────────────────────────

if (isset($argv) && realpath($argv[0]) === realpath(__FILE__)) {
    $composerJsonPath = $argv[1] ?? '';

    if ($composerJsonPath === '') {
        fwrite(STDOUT, json_encode([
            'event' => 'stage_warning',
            'stage' => 'PhpVersionGuard',
            'message' => 'No composer.json path provided to PhpVersionGuard',
        ]) . "\n");
        exit(0);
    }

    $guard = new PhpVersionGuard();
    $result = $guard->check($composerJsonPath);

    if ($result['status'] === 'warning') {
        fwrite(STDOUT, json_encode([
            'event' => 'stage_warning',
            'stage' => 'PhpVersionGuard',
            'message' => $result['message'],
            'declared_constraint' => $result['declared_constraint'],
            'php_minimum' => $result['php_minimum'],
        ]) . "\n");
    } elseif ($result['status'] === 'error') {
        fwrite(STDERR, '[PhpVersionGuard] ' . $result['message'] . "\n");
    } else {
        fwrite(STDERR, '[PhpVersionGuard] ' . $result['message'] . "\n");
    }

    exit(0);
}
