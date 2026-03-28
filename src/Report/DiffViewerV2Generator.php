<?php

declare(strict_types=1);

namespace App\Report;

use App\Report\Renderer\AnnotationRenderer;
use App\Report\Renderer\FileTreeRenderer;
use App\Report\Renderer\HopSectionRenderer;
use RuntimeException;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Generates a self-contained HTML diff viewer (v2) from a {@see ChainReport}.
 *
 * Output is a single HTML file with all CSS/JS inlined — no external dependencies,
 * no CDN requests. Suitable for opening directly in a browser via file:// protocol.
 */
final class DiffViewerV2Generator
{
    private readonly HopSectionRenderer $hopSectionRenderer;
    private readonly FileTreeRenderer $fileTreeRenderer;
    private readonly Environment $twig;

    public function __construct(
        private readonly string $assetsDir,
        private readonly string $templatesDir,
        ?HopSectionRenderer $hopSectionRenderer = null,
        ?FileTreeRenderer $fileTreeRenderer = null,
        ?Environment $twig = null,
    ) {
        $this->hopSectionRenderer = $hopSectionRenderer ?? new HopSectionRenderer(new AnnotationRenderer());
        $this->fileTreeRenderer   = $fileTreeRenderer ?? new FileTreeRenderer();

        if ($twig !== null) {
            $this->twig = $twig;
        } else {
            $loader     = new FilesystemLoader($this->templatesDir);
            $this->twig = new Environment($loader, [
                'autoescape'       => 'html',
                'strict_variables' => true,
            ]);
        }
    }

    /**
     * Generate a self-contained HTML report.
     *
     * @param array<string, list<array{file: string, diff: string, rules: list<string>}>> $hopFileDiffs
     *   Map of hop key (e.g. "8->9") to list of file diffs for that hop.
     */
    public function generate(
        ChainReport $chainReport,
        array $hopFileDiffs = [],
        string $generatedAt = '',
    ): string {
        if ($generatedAt === '') {
            $generatedAt = date('Y-m-d H:i:s T');
        }

        $diff2htmlCss = $this->readAsset($this->assetsDir . '/diff2html.min.css');
        $diff2htmlJs  = $this->readAsset($this->assetsDir . '/diff2html.min.js');
        $reportCss    = $this->readAsset($this->templatesDir . '/report/assets/diff-viewer-v2.css');
        $reportJs     = $this->readAsset($this->templatesDir . '/report/assets/diff-viewer-v2.js');

        $hopSections = [];
        foreach ($chainReport->hopReports as $hopReport) {
            $fileDiffs     = $hopFileDiffs[$hopReport->hopKey] ?? [];
            $hopSections[] = [
                'hopKey'    => $hopReport->hopKey,
                'from'      => $hopReport->fromVersion,
                'to'        => $hopReport->toVersion,
                'html'      => $this->hopSectionRenderer->render($hopReport, $fileDiffs),
                'summary'   => $this->buildHopSummary($hopReport, $fileDiffs),
            ];
        }

        $allFiles    = $this->collectAllFiles($chainReport, $hopFileDiffs);
        $fileTreeHtml = $this->fileTreeRenderer->render($allFiles);

        return $this->twig->render('report/diff-viewer-v2.html.twig', [
            'chainId'       => $chainReport->chainId,
            'sourceVersion' => $chainReport->sourceVersion,
            'targetVersion' => $chainReport->targetVersion,
            'generatedAt'   => $generatedAt,
            'hopSections'   => $hopSections,
            'fileTreeHtml'  => $fileTreeHtml,
            'diff2htmlCss'  => $diff2htmlCss,
            'diff2htmlJs'   => $diff2htmlJs,
            'reportCss'     => $reportCss,
            'reportJs'      => $reportJs,
            'totalHops'     => count($chainReport->hopReports),
            'totalEvents'   => $chainReport->totalEvents,
        ]);
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Collect all changed files across all hops for the file tree sidebar.
     *
     * Files appearing in manual_review_required events are tagged as 'review';
     * all others default to 'auto'.
     *
     * @param array<string, list<array{file: string, diff: string, rules: list<string>}>> $hopFileDiffs
     * @return list<array{file: string, changeType: string}>
     */
    private function collectAllFiles(ChainReport $chainReport, array $hopFileDiffs): array
    {
        /** @var array<string, array{file: string, changeType: string}> $index */
        $index = [];

        foreach ($chainReport->hopReports as $hopReport) {
            $fileDiffs = $hopFileDiffs[$hopReport->hopKey] ?? [];

            foreach ($fileDiffs as $fileDiff) {
                $file = $fileDiff['file'];
                if (!isset($index[$file])) {
                    $index[$file] = ['file' => $file, 'changeType' => 'auto'];
                }
            }

            foreach ($hopReport->events as $event) {
                if ((string) ($event['event'] ?? '') !== 'manual_review_required') {
                    continue;
                }
                foreach ((array) ($event['files'] ?? []) as $file) {
                    if (!is_string($file) || $file === '') {
                        continue;
                    }
                    $index[$file] = ['file' => $file, 'changeType' => 'review'];
                }
            }
        }

        return array_values($index);
    }

    /**
     * Build a per-hop summary for the tab header display.
     *
     * @param list<array{file: string, diff: string, rules: list<string>}> $fileDiffs
     * @return array{filesChanged: int, manualReview: int, events: int}
     */
    private function buildHopSummary(HopReport $hopReport, array $fileDiffs): array
    {
        $manualReview = 0;
        foreach ($hopReport->events as $event) {
            if ((string) ($event['event'] ?? '') === 'manual_review_required') {
                $manualReview++;
            }
        }

        return [
            'filesChanged' => count($fileDiffs),
            'manualReview' => $manualReview,
            'events'       => $hopReport->eventCount,
        ];
    }

    private function readAsset(string $path): string
    {
        if (!file_exists($path)) {
            throw new RuntimeException("Asset file not found: {$path}");
        }
        return (string) file_get_contents($path);
    }
}
