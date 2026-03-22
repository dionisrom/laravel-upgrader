<?php

declare(strict_types=1);

namespace AppContainer\Report;

use AppContainer\Report\Formatters\HtmlFormatter;
use AppContainer\Report\Formatters\JsonFormatter;
use AppContainer\Report\Formatters\MarkdownFormatter;
use RuntimeException;

final class ReportBuilder
{
    public function __construct(
        private readonly string $outputDir,
        private readonly string $assetsDir,
        private readonly ConfidenceScorer $scorer = new ConfidenceScorer(),
    ) {}

    /**
     * Generate all report files into $outputDir.
     *
     * @return list<string>
     */
    public function build(ReportData $data): array
    {
        if (!is_dir($this->outputDir)) {
            if (!mkdir($this->outputDir, 0755, true) && !is_dir($this->outputDir)) {
                throw new RuntimeException("Failed to create output directory: {$this->outputDir}");
            }
        }

        $htmlFormatter     = new HtmlFormatter($this->assetsDir, $this->scorer);
        $jsonFormatter     = new JsonFormatter($this->scorer);
        $markdownFormatter = new MarkdownFormatter();

        $files   = [];
        $files[] = $this->write('report.html', $htmlFormatter->format($data));
        $files[] = $this->write('report.json', $jsonFormatter->format($data));
        $files[] = $this->write('manual-review.md', $markdownFormatter->format($data));

        return $files;
    }

    private function write(string $filename, string $content): string
    {
        $path = rtrim($this->outputDir, '/\\') . DIRECTORY_SEPARATOR . $filename;
        file_put_contents($path, $content);
        return $path;
    }
}
