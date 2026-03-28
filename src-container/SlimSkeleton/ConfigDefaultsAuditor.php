<?php

declare(strict_types=1);

namespace AppContainer\SlimSkeleton;

use PhpParser\ParserFactory;
use PhpParser\Error as ParserError;

/**
 * Audits `config/*.php` files against L11 defaults and emits info-severity
 * manual review items for keys that have changed in L11.
 *
 * (Design spike §5.1, TRD-P2SLIM-001)
 */
final class ConfigDefaultsAuditor
{
    public function audit(string $workspacePath): ConfigAuditResult
    {
        $configDir = $workspacePath . '/config';

        if (!is_dir($configDir)) {
            return ConfigAuditResult::success([]);
        }

        $items = [];

        // Check for migration numbering (L11 new convention)
        $migrationsDir = $workspacePath . '/database/migrations';
        if (is_dir($migrationsDir)) {
            $items[] = SlimSkeletonManualReviewItem::config(
                $migrationsDir,
                0,
                'L11 introduces optional date-based migration numbering (0001_01_01_000000_*). Existing timestamp-based migrations continue to work.',
                'info',
                'No action required. New migrations may use the new or old naming convention.'
            );
        }

        // Check config/database.php for SQLite default change
        $dbConfig = $configDir . '/database.php';
        if (file_exists($dbConfig)) {
            $content = file_get_contents($dbConfig);
            if ($content !== false && str_contains($content, 'DB_CONNECTION') === false) {
                $items[] = SlimSkeletonManualReviewItem::config(
                    $dbConfig,
                    0,
                    'L11 defaults to SQLite database driver — verify DB_CONNECTION is set in .env.',
                    'info',
                    "Add DB_CONNECTION=mysql (or your driver) to .env if not already set."
                );
            }
        }

        // Check config/queue.php for sync driver default
        $queueConfig = $configDir . '/queue.php';
        if (file_exists($queueConfig)) {
            $content = file_get_contents($queueConfig);
            if ($content !== false && str_contains($content, 'sync') !== false) {
                $items[] = SlimSkeletonManualReviewItem::config(
                    $queueConfig,
                    0,
                    "L11 defaults QUEUE_CONNECTION to 'sync'. Verify QUEUE_CONNECTION is set in .env.",
                    'info',
                    "Add QUEUE_CONNECTION=redis (or your driver) to .env if not already set."
                );
            }
        }

        // Check config/sanctum.php for encrypt_cookies option
        $sanctumConfig = $configDir . '/sanctum.php';
        if (file_exists($sanctumConfig)) {
            $content = file_get_contents($sanctumConfig);
            if ($content !== false && !str_contains($content, 'encrypt_cookies')) {
                $items[] = SlimSkeletonManualReviewItem::config(
                    $sanctumConfig,
                    0,
                    "L11 Sanctum adds 'encrypt_cookies' option (default: false). Consider adding to config/sanctum.php.",
                    'info',
                    "Add 'encrypt_cookies' => env('SANCTUM_ENCRYPT_COOKIES', false) to config/sanctum.php."
                );
            }
        }

        // Check vite.config.js (non-breaking but worth noting)
        $viteConfig = $workspacePath . '/vite.config.js';
        if (file_exists($viteConfig)) {
            $items[] = SlimSkeletonManualReviewItem::config(
                $viteConfig,
                0,
                'Vite config gains SSR plugin support in L11 — existing vite.config.js remains functional.',
                'info',
                'No action required. Optionally add ssr() plugin configuration if needed.'
            );
        }

        $this->emitEvent('slim_config_audited', [
            'workspace'    => $workspacePath,
            'item_count'   => count($items),
        ]);

        return ConfigAuditResult::success($items);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function emitEvent(string $event, array $data): void
    {
        $payload = array_merge(['event' => $event], $data);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }
}
