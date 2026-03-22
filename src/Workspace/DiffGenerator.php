<?php

declare(strict_types=1);

namespace App\Workspace;

final class DiffGenerator
{
    /**
     * Generates a unified diff string between original and new content.
     * Used for the report generator (P1-18).
     */
    public function generateUnifiedDiff(
        string $originalContent,
        string $newContent,
        string $filename = 'file'
    ): string {
        if ($originalContent === $newContent) {
            return '';
        }

        $originalLines = explode("\n", $originalContent);
        $newLines = explode("\n", $newContent);

        $header = sprintf(
            "--- %s\n+++ %s\n",
            'a/' . $filename,
            'b/' . $filename
        );

        $hunks = $this->computeHunks($originalLines, $newLines);

        if ($hunks === '') {
            return '';
        }

        return $header . $hunks;
    }

    /**
     * Computes unified diff hunks between two line arrays.
     *
     * @param array<int, string> $originalLines
     * @param array<int, string> $newLines
     */
    private function computeHunks(array $originalLines, array $newLines): string
    {
        $lcs = $this->longestCommonSubsequence($originalLines, $newLines);
        $edits = $this->buildEditScript($originalLines, $newLines, $lcs);

        return $this->formatHunks($edits, $originalLines, $newLines);
    }

    /**
     * Builds an edit script (list of operations) from original to new.
     *
     * Each entry: ['op' => 'keep'|'remove'|'add', 'orig_line' => int, 'new_line' => int]
     *
     * @param array<int, string> $original
     * @param array<int, string> $new
     * @param array<int, array{int, int}> $lcs LCS as pairs [origIdx, newIdx]
     * @return array<int, array{op: string, orig_line: int, new_line: int}>
     */
    private function buildEditScript(array $original, array $new, array $lcs): array
    {
        $edits = [];
        $origIdx = 0;
        $newIdx = 0;

        foreach ($lcs as [$lcsOrig, $lcsNew]) {
            while ($origIdx < $lcsOrig) {
                $edits[] = ['op' => 'remove', 'orig_line' => $origIdx, 'new_line' => -1];
                $origIdx++;
            }
            while ($newIdx < $lcsNew) {
                $edits[] = ['op' => 'add', 'orig_line' => -1, 'new_line' => $newIdx];
                $newIdx++;
            }
            $edits[] = ['op' => 'keep', 'orig_line' => $origIdx, 'new_line' => $newIdx];
            $origIdx++;
            $newIdx++;
        }

        while ($origIdx < count($original)) {
            $edits[] = ['op' => 'remove', 'orig_line' => $origIdx, 'new_line' => -1];
            $origIdx++;
        }
        while ($newIdx < count($new)) {
            $edits[] = ['op' => 'add', 'orig_line' => -1, 'new_line' => $newIdx];
            $newIdx++;
        }

        return $edits;
    }

    /**
     * Groups edits into unified diff hunks with 3-line context.
     *
     * @param array<int, array{op: string, orig_line: int, new_line: int}> $edits
     * @param array<int, string> $original
     * @param array<int, string> $new
     */
    private function formatHunks(array $edits, array $original, array $new): string
    {
        $context = 3;
        $output = '';
        $n = count($edits);

        // Identify changed regions (non-keep)
        $changedAt = [];
        foreach ($edits as $i => $edit) {
            if ($edit['op'] !== 'keep') {
                $changedAt[] = $i;
            }
        }

        if ($changedAt === []) {
            return '';
        }

        // Group into hunk windows
        $hunkGroups = [];
        $start = max(0, $changedAt[0] - $context);
        $end = min($n - 1, $changedAt[0] + $context);
        $group = [$start, $end];

        for ($i = 1; $i < count($changedAt); $i++) {
            $cs = max(0, $changedAt[$i] - $context);
            $ce = min($n - 1, $changedAt[$i] + $context);

            if ($cs <= $group[1] + 1) {
                $group[1] = max($group[1], $ce);
            } else {
                $hunkGroups[] = $group;
                $group = [$cs, $ce];
            }
        }
        $hunkGroups[] = $group;

        foreach ($hunkGroups as [$hunkStart, $hunkEnd]) {
            $slicedEdits = array_slice($edits, $hunkStart, $hunkEnd - $hunkStart + 1);

            $origStart = null;
            $origCount = 0;
            $newStart = null;
            $newCount = 0;
            $lines = [];

            foreach ($slicedEdits as $edit) {
                if ($edit['op'] === 'keep') {
                    if ($origStart === null) {
                        $origStart = $edit['orig_line'] + 1;
                        $newStart = $edit['new_line'] + 1;
                    }
                    $origCount++;
                    $newCount++;
                    $lines[] = ' ' . $original[$edit['orig_line']];
                } elseif ($edit['op'] === 'remove') {
                    if ($origStart === null) {
                        $origStart = $edit['orig_line'] + 1;
                        $newStart = ($slicedEdits[0]['new_line'] >= 0)
                            ? $slicedEdits[0]['new_line'] + 1
                            : 1;
                    }
                    $origCount++;
                    $lines[] = '-' . $original[$edit['orig_line']];
                } elseif ($edit['op'] === 'add') {
                    if ($newStart === null) {
                        $newStart = $edit['new_line'] + 1;
                        $origStart = ($slicedEdits[0]['orig_line'] >= 0)
                            ? $slicedEdits[0]['orig_line'] + 1
                            : 1;
                    }
                    $newCount++;
                    $lines[] = '+' . $new[$edit['new_line']];
                }
            }

            if ($origStart === null) {
                $origStart = 1;
            }
            if ($newStart === null) {
                $newStart = 1;
            }

            $output .= sprintf(
                "@@ -%d,%d +%d,%d @@\n",
                $origStart,
                $origCount,
                $newStart,
                $newCount
            );
            $output .= implode("\n", $lines) . "\n";
        }

        return $output;
    }

    /**
     * Computes the Longest Common Subsequence of two string arrays.
     * Returns pairs of [origIndex, newIndex] matching lines.
     *
     * @param array<int, string> $a
     * @param array<int, string> $b
     * @return array<int, array{int, int}>
     */
    private function longestCommonSubsequence(array $a, array $b): array
    {
        $m = count($a);
        $n = count($b);

        // DP table
        $dp = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));

        for ($i = 1; $i <= $m; $i++) {
            for ($j = 1; $j <= $n; $j++) {
                if ($a[$i - 1] === $b[$j - 1]) {
                    $dp[$i][$j] = $dp[$i - 1][$j - 1] + 1;
                } else {
                    $dp[$i][$j] = max($dp[$i - 1][$j], $dp[$i][$j - 1]);
                }
            }
        }

        // Backtrack
        $result = [];
        $i = $m;
        $j = $n;

        while ($i > 0 && $j > 0) {
            if ($a[$i - 1] === $b[$j - 1]) {
                array_unshift($result, [$i - 1, $j - 1]);
                $i--;
                $j--;
            } elseif ($dp[$i - 1][$j] > $dp[$i][$j - 1]) {
                $i--;
            } else {
                $j--;
            }
        }

        return $result;
    }
}
