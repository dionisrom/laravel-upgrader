<?php

declare(strict_types=1);

namespace App\Report;

use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder;

final class DirectoryDiffCollector
{
    /**
     * @return list<array{file: string, diff: string, rules: list<string>}>
     */
    public function collect(string $beforeDir, string $afterDir): array
    {
        $beforeFiles = $this->indexFiles($beforeDir);
        $afterFiles  = $this->indexFiles($afterDir);

        $paths = array_values(array_unique([...array_keys($beforeFiles), ...array_keys($afterFiles)]));
        sort($paths);

        $diffs = [];

        foreach ($paths as $relativePath) {
            $beforePath = $beforeFiles[$relativePath] ?? null;
            $afterPath  = $afterFiles[$relativePath] ?? null;

            $beforeContent = $beforePath !== null ? (string) file_get_contents($beforePath) : '';
            $afterContent  = $afterPath !== null ? (string) file_get_contents($afterPath) : '';

            if ($beforePath !== null && $afterPath !== null && $beforeContent === $afterContent) {
                continue;
            }

            if ($this->isBinary($beforeContent) || $this->isBinary($afterContent)) {
                $diff = $this->binaryDiff($relativePath);
            } else {
                $builder = new UnifiedDiffOutputBuilder(sprintf("--- a/%s\n+++ b/%s\n", $relativePath, $relativePath));
                $differ  = new Differ($builder);
                $diff    = $differ->diff($beforeContent, $afterContent);
            }

            if ($diff === '') {
                continue;
            }

            $diffs[] = [
                'file'  => $relativePath,
                'diff'  => $diff,
                'rules' => [],
            ];
        }

        return $diffs;
    }

    /**
     * @return array<string, string>
     */
    private function indexFiles(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }

            $relativePath = str_replace('\\', '/', substr($item->getPathname(), strlen(rtrim($directory, '\\/')) + 1));
            if ($this->shouldSkip($relativePath)) {
                continue;
            }

            $files[$relativePath] = $item->getPathname();
        }

        return $files;
    }

    private function shouldSkip(string $relativePath): bool
    {
        $prefixes = ['.git/', '.upgrader/', 'vendor/', 'node_modules/'];
        foreach ($prefixes as $prefix) {
            if (str_starts_with($relativePath, $prefix)) {
                return true;
            }
        }

        return in_array($relativePath, ['report.html', 'report.json', 'manual-review.md', 'audit.log.json'], true);
    }

    private function isBinary(string $content): bool
    {
        return $content !== '' && str_contains($content, "\0");
    }

    private function binaryDiff(string $relativePath): string
    {
        return sprintf(
            "--- a/%s\n+++ b/%s\n@@ -1 +1 @@\n-Binary content changed\n+Binary content changed\n",
            $relativePath,
            $relativePath,
        );
    }
}