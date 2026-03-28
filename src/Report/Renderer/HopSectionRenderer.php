<?php

declare(strict_types=1);

namespace App\Report\Renderer;

use App\Report\HopReport;

/**
 * Renders the HTML section for a single hop, including per-file diff blocks,
 * Rector rule annotations, and sign-off checkboxes.
 */
final class HopSectionRenderer
{
    public function __construct(
        private readonly AnnotationRenderer $annotationRenderer,
    ) {}

    /**
     * Render the full hop section HTML.
     *
     * @param list<array{file: string, diff: string, rules: list<string>, confidence?: string}> $fileDiffs
     */
    public function render(HopReport $hopReport, array $fileDiffs): string
    {
        $manualReviewFiles = $this->collectManualReviewFiles($hopReport);

        if ($fileDiffs === []) {
            $label = htmlspecialchars($hopReport->hopKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            return <<<HTML
            <div class="hop-empty">
              <p>No file diffs recorded for hop <strong>{$label}</strong>.</p>
            </div>
            HTML;
        }

        $blocksHtml = '';
        foreach ($fileDiffs as $fileDiff) {
            $blocksHtml .= $this->renderFileDiffBlock(
                $fileDiff['file'],
                $fileDiff['diff'],
                $fileDiff['rules'],
                $manualReviewFiles,
                $fileDiff['confidence'] ?? 'high',
            );
        }

        return $blocksHtml;
    }

    /**
     * Extract files requiring manual review from hop events.
     *
     * @return array<string, true>
     */
    private function collectManualReviewFiles(HopReport $hopReport): array
    {
        $files = [];
        foreach ($hopReport->events as $event) {
            if ((string) ($event['event'] ?? '') !== 'manual_review_required') {
                continue;
            }
            foreach ((array) ($event['files'] ?? []) as $file) {
                if (is_string($file) && $file !== '') {
                    $files[$file] = true;
                }
            }
        }
        return $files;
    }

    /**
     * @param list<string>         $rules
     * @param array<string, true>  $manualReviewFiles
     */
    private function renderFileDiffBlock(
        string $file,
        string $diff,
        array $rules,
        array $manualReviewFiles,
        string $confidence = 'high',
    ): string {
        $changeType      = isset($manualReviewFiles[$file]) ? 'review' : 'auto';
        $fileEsc         = htmlspecialchars($file, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $changeTypeEsc   = htmlspecialchars($changeType, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $ext             = pathinfo($file, PATHINFO_EXTENSION);
        $extEsc          = htmlspecialchars($ext, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $dir             = htmlspecialchars(dirname($file), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $diffHtmlEncoded = htmlspecialchars($diff, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $annotations     = $this->annotationRenderer->render($rules);
        $label           = htmlspecialchars(ucfirst($changeType), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $checkboxId      = 'signoff-' . substr(md5($file), 0, 12);
        $confidenceEsc   = htmlspecialchars($confidence, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
        <div class="file-diff-block"
             id="diff-{$fileEsc}"
             data-file="{$fileEsc}"
             data-change-type="{$changeTypeEsc}"
             data-confidence="{$confidenceEsc}"
             data-ext="{$extEsc}"
             data-dir="{$dir}">
          <div class="file-diff-header">
            <span class="filename" title="{$fileEsc}">{$fileEsc}</span>
            <span class="change-badge badge-{$changeTypeEsc}" aria-label="Change type: {$label}">{$label}</span>
            {$annotations}
            <label class="signoff-label" title="Mark as reviewed">
              <input type="checkbox"
                     class="signoff-checkbox"
                     id="{$checkboxId}"
                     data-file="{$fileEsc}"
                     aria-label="Sign off {$fileEsc}">
              Signed off
            </label>
          </div>
          <div class="review-note-container" data-file="{$fileEsc}">
            <button class="review-note-toggle" type="button" aria-label="Toggle review note for {$fileEsc}">📝 Note</button>
            <div class="review-note-editor hidden">
              <textarea class="review-note-text" placeholder="Add review notes…" aria-label="Review note for {$fileEsc}"></textarea>
            </div>
          </div>
          <div class="diff-container">
            <pre class="unified-diff" data-diff="{$diffHtmlEncoded}" aria-hidden="true"></pre>
            <div class="d2h-wrapper" aria-label="Diff for {$fileEsc}"></div>
          </div>
        </div>
        HTML;
    }
}
