<?php

declare(strict_types=1);

namespace AppContainer\Rector;

final class RectorConfigBuilder
{
    /**
     * Generates a dynamic rector.php config file and writes it to $outputPath.
     *
     * @param string   $workspacePath Absolute path to the repository being upgraded
     * @param string[] $ruleClasses   Fully-qualified class names of Rector rules to apply
     * @param string   $outputPath    Absolute path where the generated config will be written
     *
     * @return string The path the file was written to ($outputPath)
     */
    public function build(string $workspacePath, array $ruleClasses, string $outputPath): string
    {
        $skipPaths = [
            $workspacePath . '/.upgrader-state',
            $workspacePath . '/vendor',
            $workspacePath . '/storage',
        ];

        $lines = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = 'declare(strict_types=1);';
        $lines[] = '';
        $lines[] = 'use Rector\Config\RectorConfig;';
        $lines[] = '';
        $lines[] = 'return static function (RectorConfig $rectorConfig): void {';
        $lines[] = sprintf(
            '    $rectorConfig->paths([%s]);',
            $this->exportString($workspacePath),
        );
        $lines[] = '';

        foreach ($skipPaths as $skipPath) {
            $lines[] = sprintf(
                '    $rectorConfig->skip([%s]);',
                $this->exportString($skipPath),
            );
        }

        if ($ruleClasses !== []) {
            $lines[] = '';
            foreach ($ruleClasses as $ruleClass) {
                $lines[] = sprintf(
                    '    $rectorConfig->rule(%s::class);',
                    $this->exportFqcn($ruleClass),
                );
            }
        }

        $lines[] = '};';
        $lines[] = '';

        $content = implode("\n", $lines);

        $bytesWritten = file_put_contents($outputPath, $content);

        if ($bytesWritten === false) {
            throw new \RuntimeException(
                sprintf('Failed to write Rector config to "%s"', $outputPath),
            );
        }

        return $outputPath;
    }

    /**
     * Exports a plain string value as a PHP string literal (single-quoted, escaped).
     */
    private function exportString(string $value): string
    {
        return "'" . addcslashes($value, "'\\") . "'";
    }

    /**
     * Builds a use-statement-free FQCN reference for a rule class.
     * Adds a use statement as a comment so the generated file stays readable,
     * but emits a fully-qualified reference in the rule() call to avoid
     * requiring dynamic use imports in the generated file.
     */
    private function exportFqcn(string $fqcn): string
    {
        // Emit as backslash-prefixed FQCN literal so no use statement is needed
        return '\\' . ltrim($fqcn, '\\');
    }
}
