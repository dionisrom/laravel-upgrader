<?php

declare(strict_types=1);

namespace AppContainer\Config;

/**
 * Orchestrates the atomic config migration for the Laravel 8 → 9 hop.
 *
 * Safety invariant: partial migration is IMPOSSIBLE.
 * All config/*.php and .env* files are snapshotted before any file is touched.
 * On ANY Throwable the snapshot is fully restored before returning failure.
 *
 * JSON-ND events are emitted to stdout throughout the migration so the host
 * orchestrator can stream progress to the dashboard.
 */
final class ConfigMigrator
{
    public function __construct(
        private readonly ConfigSnapshotManager $snapshotManager,
        private readonly ConfigMerger $merger,
        private readonly EnvMigrator $envMigrator,
        private readonly SafeConfigParser $configParser = new SafeConfigParser(),
    ) {}

    public function migrate(string $workspacePath): MigrationResult
    {
        $this->emit('config.migration_started', [
            'workspace' => $workspacePath,
            'hop' => '8-to-9',
        ]);

        $snapshotPath = $this->snapshotManager->snapshot($workspacePath);
        $appliedMigrations = [];

        try {
            foreach ($this->getMigrations() as $name => $migration) {
                $migration($workspacePath);
                $appliedMigrations[] = $name;
                $this->emit('config.migration_applied', ['migration' => $name]);
            }

            // .env migration is inside the atomic block so snapshot rollback covers it
            $envResult = $this->envMigrator->migrate($workspacePath);
            if (!$envResult->success) {
                throw new \RuntimeException('Env migration failed: ' . ($envResult->errorMessage ?? 'unknown'));
            }
            if ($envResult->renamedKeys !== [] || $envResult->addedKeys !== []) {
                $appliedMigrations[] = 'env_migration';
                $this->emit('config.migration_applied', ['migration' => 'env_migration']);
            }
        } catch (\Throwable $e) {
            $this->emit('config.migration_rollback', ['reason' => $e->getMessage()]);
            $this->snapshotManager->restore($snapshotPath, $workspacePath);
            $this->snapshotManager->cleanup($snapshotPath);
            return MigrationResult::failure($e->getMessage(), $snapshotPath);
        }

        $this->snapshotManager->cleanup($snapshotPath);
        $this->emit('config.migration_completed', ['applied' => count($appliedMigrations)]);

        return MigrationResult::success($appliedMigrations);
    }

    // -------------------------------------------------------------------------
    // Migration registry
    // -------------------------------------------------------------------------

    /**
     * Returns an ordered map of migration name → callable for the L8→L9 hop.
     *
     * @return array<string, callable(string): void>
     */
    private function getMigrations(): array
    {
        return [
            'auth_guards_change'        => fn(string $wp) => $this->migrateAuthConfig($wp),
            'cache_redis_driver'        => fn(string $wp) => $this->migrateCacheConfig($wp),
            'filesystem_flysystem_v3'   => fn(string $wp) => $this->migrateFilesystemConfig($wp),
            'mail_smtp_credentials'     => fn(string $wp) => $this->migrateMailConfig($wp),
            'session_same_site_default' => fn(string $wp) => $this->migrateSessionConfig($wp),
        ];
    }

    // -------------------------------------------------------------------------
    // Individual L8 → L9 config migrations
    // -------------------------------------------------------------------------

    /**
     * config/auth.php — ensure password-reset brokers have a `throttle` key.
     *
     * Laravel 9 added a per-broker `throttle` default of 60 seconds.
     * @see https://laravel.com/docs/9.x/upgrade#password-reset-link-expiration-date
     */
    private function migrateAuthConfig(string $workspacePath): void
    {
        $path = $workspacePath . '/config/auth.php';
        if (!file_exists($path)) {
            return;
        }

        $config = $this->loadConfigFile($path);

        if (!isset($config['passwords']) || !is_array($config['passwords'])) {
            return;
        }

        $changes = [];
        foreach ($config['passwords'] as $provider => $settings) {
            if (is_array($settings) && !array_key_exists('throttle', $settings)) {
                $changes[$provider] = ['throttle' => 60];
            }
        }

        if ($changes === []) {
            return;
        }

        // Add throttle to each broker that lacks it; existing keys preserved by merger
        $merged = $this->merger->merge(
            $config,
            ['passwords' => $changes],
            ['passwords']
        );

        $this->writeConfigFile($path, $merged);
    }

    /**
     * config/cache.php — add explicit `client` key to Redis cache store.
     *
     * Laravel 9 no longer auto-selects predis; the client must be specified.
     * We default to `phpredis` (Laravel 9 default when extension is loaded).
     * @see https://laravel.com/docs/9.x/upgrade#redis-cache-tags
     */
    private function migrateCacheConfig(string $workspacePath): void
    {
        $path = $workspacePath . '/config/cache.php';
        if (!file_exists($path)) {
            return;
        }

        $config = $this->loadConfigFile($path);

        if (!isset($config['stores']['redis']) || !is_array($config['stores']['redis'])) {
            return;
        }

        if (array_key_exists('client', $config['stores']['redis'])) {
            return; // already set, respect user choice
        }

        // Add 'client' to the redis store
        $merged = $this->merger->merge(
            $config,
            ['stores' => ['redis' => ['client' => 'phpredis']]],
            [] // only adding a new key, no overwrite needed
        );

        $this->writeConfigFile($path, $merged);
    }

    /**
     * config/filesystems.php — Flysystem 3.x adapter changes.
     *
     * Changes applied:
     *  - local disk: `permissions` key renamed to `visibility`
     *  - s3 disk: redundant `options.visibility => private` removed
     *    (private is the Flysystem 3 default)
     *
     * @see https://laravel.com/docs/9.x/upgrade#flysystem-3
     */
    private function migrateFilesystemConfig(string $workspacePath): void
    {
        $path = $workspacePath . '/config/filesystems.php';
        if (!file_exists($path)) {
            return;
        }

        $config = $this->loadConfigFile($path);
        $modified = false;

        // local disk: permissions → visibility
        if (
            isset($config['disks']['local'])
            && is_array($config['disks']['local'])
            && array_key_exists('permissions', $config['disks']['local'])
            && !array_key_exists('visibility', $config['disks']['local'])
        ) {
            /** @var array<string, mixed> $localDisk */
            $localDisk = $config['disks']['local'];
            $localDisk['visibility'] = $localDisk['permissions'];
            unset($localDisk['permissions']);
            $config['disks']['local'] = $localDisk;
            $modified = true;
        }

        // s3 disk: remove redundant options.visibility = 'private'
        if (
            isset($config['disks']['s3'])
            && is_array($config['disks']['s3'])
            && isset($config['disks']['s3']['options'])
            && is_array($config['disks']['s3']['options'])
            && ($config['disks']['s3']['options']['visibility'] ?? null) === 'private'
        ) {
            /** @var array<string, mixed> $s3Options */
            $s3Options = $config['disks']['s3']['options'];
            unset($s3Options['visibility']);
            /** @var array<string, mixed> $s3Disk */
            $s3Disk = $config['disks']['s3'];
            if ($s3Options === []) {
                unset($s3Disk['options']);
            } else {
                $s3Disk['options'] = $s3Options;
            }
            $config['disks']['s3'] = $s3Disk;
            $modified = true;
        }

        if (!$modified) {
            return;
        }

        $this->writeConfigFile($path, $config);
    }

    /**
     * config/mail.php — remove deprecated `auth_mode` key from smtp mailer.
     *
     * The `auth_mode` option was removed in Symfony Mailer (Laravel 9).
     * @see https://laravel.com/docs/9.x/upgrade#the-smtp-mailer
     */
    private function migrateMailConfig(string $workspacePath): void
    {
        $path = $workspacePath . '/config/mail.php';
        if (!file_exists($path)) {
            return;
        }

        $config = $this->loadConfigFile($path);

        if (
            !isset($config['mailers']['smtp'])
            || !is_array($config['mailers']['smtp'])
            || !array_key_exists('auth_mode', $config['mailers']['smtp'])
        ) {
            return;
        }

        /** @var array<string, mixed> $smtpMailer */
        $smtpMailer = $config['mailers']['smtp'];
        unset($smtpMailer['auth_mode']);
        $config['mailers']['smtp'] = $smtpMailer;

        $this->writeConfigFile($path, $config);
    }

    /**
     * config/session.php — change `same_site` default from `null` to `'lax'`.
     *
     * Laravel 9 changed the default to 'lax' for improved CSRF protection.
     * Only applied when the value is exactly null (the L8 default), so
     * user-set values (e.g. 'strict', 'none') are never overwritten.
     *
     * @see https://laravel.com/docs/9.x/upgrade#session
     */
    private function migrateSessionConfig(string $workspacePath): void
    {
        $path = $workspacePath . '/config/session.php';
        if (!file_exists($path)) {
            return;
        }

        $config = $this->loadConfigFile($path);

        if (!array_key_exists('same_site', $config) || $config['same_site'] !== null) {
            return; // not the L8 default — respect user choice
        }

        $merged = $this->merger->merge(
            $config,
            ['same_site' => 'lax'],
            ['same_site']
        );

        $this->writeConfigFile($path, $merged);
    }

    // -------------------------------------------------------------------------
    // File I/O helpers
    // -------------------------------------------------------------------------

    /**
     * Safely parse a Laravel config file using AST analysis (no code execution).
     *
     * @return array<string, mixed>
     * @throws \RuntimeException if the file cannot be parsed or doesn't return an array
     */
    private function loadConfigFile(string $path): array
    {
        return $this->configParser->parse($path);
    }

    /**
     * Write a config array back to disk atomically (.tmp → rename).
     *
     * @param array<string, mixed> $config
     * @throws \RuntimeException on I/O failure
     */
    private function writeConfigFile(string $path, array $config): void
    {
        $content = $this->merger->renderPhpConfig($config);
        $tmp = $path . '.tmp';

        if (file_put_contents($tmp, $content) === false) {
            throw new \RuntimeException("Cannot write config file: {$tmp}");
        }

        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Cannot rename config file {$tmp} → {$path}");
        }
    }

    /**
     * Emit a JSON-ND event to stdout for orchestrator consumption.
     *
     * @param array<string, mixed> $data
     */
    private function emit(string $type, array $data): void
    {
        echo json_encode(['type' => $type, 'data' => $data]) . "\n";
    }
}
