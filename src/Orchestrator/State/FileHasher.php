<?php

declare(strict_types=1);

namespace App\Orchestrator\State;

final class FileHasher
{
    private const CHUNK_SIZE = 8192; // 8 KB

    /**
     * Returns "sha256:{hex}" for the file at $path.
     * Reads file in 8 KB chunks for memory efficiency.
     *
     * @throws \RuntimeException if file not found or not readable
     */
    public function hash(string $path): string
    {
        if (!is_file($path)) {
            throw new \RuntimeException("File not found: {$path}");
        }

        if (!is_readable($path)) {
            throw new \RuntimeException("File not readable: {$path}");
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Could not open file: {$path}");
        }

        $context = hash_init('sha256');

        try {
            while (!feof($handle)) {
                $chunk = fread($handle, self::CHUNK_SIZE);
                if ($chunk === false) {
                    throw new \RuntimeException("Error reading file: {$path}");
                }
                hash_update($context, $chunk);
            }
        } finally {
            fclose($handle);
        }

        $hex = hash_final($context);

        return "sha256:{$hex}";
    }

    /**
     * Hash multiple files.
     *
     * @param list<string> $paths Absolute paths
     * @return array<string, string> absolute path => "sha256:{hex}"
     * @throws \RuntimeException if any file is not found or not readable
     */
    public function hashMany(array $paths): array
    {
        $result = [];

        foreach ($paths as $path) {
            $result[$path] = $this->hash($path);
        }

        return $result;
    }
}
