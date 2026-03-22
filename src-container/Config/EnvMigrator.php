<?php

declare(strict_types=1);

namespace AppContainer\Config;

/**
 * Migrates a workspace's .env file for the Laravel 8 → 9 hop.
 *
 * Rules applied:
 *  - MIX_* keys: a corresponding VITE_* key is inserted immediately before
 *    the old MIX_* line; the old line is kept with a "# DEPRECATED" comment.
 *  - If any MIX_APP_URL key is found, VITE_APP_NAME=${APP_NAME} is also added.
 *  - All blank lines, # comment lines, and quoted values are preserved verbatim.
 *
 * Write strategy: temp file (.env.tmp) → atomic rename → .env.
 */
final class EnvMigrator
{
    public function migrate(string $workspacePath): EnvMigrationResult
    {
        $envPath = rtrim($workspacePath, '/\\') . '/.env';

        if (!file_exists($envPath)) {
            return new EnvMigrationResult(
                success: true,
                renamedKeys: [],
                addedKeys: [],
                errorMessage: null,
            );
        }

        try {
            $content = file_get_contents($envPath);
            if ($content === false) {
                throw new \RuntimeException("Cannot read .env file: {$envPath}");
            }

            [$newContent, $renamedKeys, $addedKeys] = $this->transform($content);

            if ($renamedKeys === [] && $addedKeys === []) {
                // Nothing to do
                return new EnvMigrationResult(
                    success: true,
                    renamedKeys: [],
                    addedKeys: [],
                    errorMessage: null,
                );
            }

            $this->writeAtomic($envPath, $newContent);

            return new EnvMigrationResult(
                success: true,
                renamedKeys: $renamedKeys,
                addedKeys: $addedKeys,
                errorMessage: null,
            );
        } catch (\Throwable $e) {
            return new EnvMigrationResult(
                success: false,
                renamedKeys: [],
                addedKeys: [],
                errorMessage: $e->getMessage(),
            );
        }
    }

    /**
     * Apply all L8→L9 .env transformations.
     *
     * @return array{0: string, 1: array<string,string>, 2: string[]}
     *         [newContent, renamedKeys (old→new), addedKeys]
     */
    private function transform(string $content): array
    {
        $lines = explode("\n", $content);

        // First pass: discover all MIX_* keys and whether MIX_APP_URL exists
        $hasMixAppUrl = false;
        foreach ($lines as $line) {
            $key = $this->extractKey($line);
            if ($key === null) {
                continue;
            }
            if ($key === 'MIX_APP_URL') {
                $hasMixAppUrl = true;
            }
        }

        $outputLines = [];
        /** @var array<string, string> $renamedKeys */
        $renamedKeys = [];
        /** @var string[] $addedKeys */
        $addedKeys = [];

        // Second pass: emit transformed lines
        foreach ($lines as $line) {
            $key = $this->extractKey($line);

            if ($key !== null && str_starts_with($key, 'MIX_')) {
                $viteKey = 'VITE_' . substr($key, 4); // strip MIX_ prefix
                $viteValue = $this->substituteKey($line, $key, $viteKey);

                // Insert VITE_* before the deprecated MIX_* line
                $outputLines[] = $viteValue;
                $outputLines[] = '# DEPRECATED: use ' . $viteKey;
                $outputLines[] = $line;

                $renamedKeys[$key] = $viteKey;
            } else {
                $outputLines[] = $line;
            }
        }

        // Add VITE_APP_NAME if MIX_APP_URL was detected
        if ($hasMixAppUrl && !in_array('VITE_APP_NAME', array_values($renamedKeys), true)) {
            $outputLines[] = 'VITE_APP_NAME=${APP_NAME}';
            $addedKeys[] = 'VITE_APP_NAME';
        }

        return [implode("\n", $outputLines), $renamedKeys, $addedKeys];
    }

    /**
     * Extract the variable key from a KEY=value line.
     * Returns null for blank lines and comment lines.
     */
    private function extractKey(string $line): ?string
    {
        $trimmed = ltrim($line);

        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            return null;
        }

        $eqPos = strpos($trimmed, '=');
        if ($eqPos === false) {
            return null;
        }

        return substr($trimmed, 0, $eqPos);
    }

    /**
     * Replace the key portion of a KEY=value line with a new key.
     */
    private function substituteKey(string $line, string $oldKey, string $newKey): string
    {
        // Only replace the first occurrence (the key portion)
        $eqPos = strpos($line, $oldKey . '=');
        if ($eqPos === false) {
            return $newKey . '=' . substr($line, strpos($line, '=') + 1);
        }

        return $newKey . '=' . substr($line, $eqPos + strlen($oldKey) + 1);
    }

    private function writeAtomic(string $path, string $content): void
    {
        $tmp = $path . '.tmp';

        if (file_put_contents($tmp, $content) === false) {
            throw new \RuntimeException("Cannot write temporary env file: {$tmp}");
        }

        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Cannot rename temporary env file to: {$path}");
        }
    }
}
