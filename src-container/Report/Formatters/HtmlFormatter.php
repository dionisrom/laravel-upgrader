<?php

declare(strict_types=1);

namespace AppContainer\Report\Formatters;

use AppContainer\Report\ConfidenceScorer;
use AppContainer\Report\ReportData;
use RuntimeException;

final class HtmlFormatter
{
    public function __construct(
        private readonly string $assetsDir,
        private readonly ConfidenceScorer $scorer,
    ) {}

    public function format(ReportData $data): string
    {
        $cssSrc = $this->assetsDir . '/diff2html.min.css';
        $jsSrc  = $this->assetsDir . '/diff2html.min.js';

        if (!file_exists($cssSrc)) {
            throw new RuntimeException("diff2html asset not found: {$cssSrc}");
        }

        if (!file_exists($jsSrc)) {
            throw new RuntimeException("diff2html asset not found: {$jsSrc}");
        }

        $diff2htmlCss = (string) file_get_contents($cssSrc);
        $diff2htmlJs  = (string) file_get_contents($jsSrc);

        $score = $this->scorer->score($data);
        $label = $this->scorer->label($score);

        $title       = $this->e("Upgrade Report: {$data->repoName} L{$data->fromVersion}→L{$data->toVersion}");
        $repoName    = $this->e($data->repoName);
        $fromVersion = $this->e($data->fromVersion);
        $toVersion   = $this->e($data->toVersion);
        $timestamp   = $this->e($data->timestamp);
        $badgeClass  = $this->badgeClass($label);
        $labelEsc    = $this->e($label);

        $autoFixed     = count($data->fileDiffs) - count($data->manualReviewItems);
        $autoFixed     = max(0, $autoFixed);
        $manualCount   = count($data->manualReviewItems);
        $blockerCount  = count($data->dependencyBlockers);

        $summaryHtml          = $this->buildSummary($data, $autoFixed, $manualCount, $blockerCount);
        $breakingChangesHtml  = $this->buildBreakingChangesIndex($data);
        $diffsHtml            = $this->buildDiffs($data);
        $manualReviewHtml     = $this->buildManualReview($data);

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
          <meta charset="UTF-8">
          <meta name="viewport" content="width=device-width, initial-scale=1.0">
          <title>{$title}</title>
          <style>
        {$diff2htmlCss}

        /* ===== Custom report styles ===== */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
          font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
          font-size: 14px;
          background: #f6f8fa;
          color: #24292f;
        }
        header {
          background: #1b1f23;
          color: #fff;
          padding: 20px 32px;
          display: flex;
          align-items: center;
          justify-content: space-between;
          gap: 16px;
          flex-wrap: wrap;
        }
        header h1 { font-size: 1.4rem; font-weight: 600; }
        header .meta { font-size: 0.85rem; color: #8b949e; }
        .badge {
          display: inline-block;
          padding: 4px 12px;
          border-radius: 20px;
          font-weight: 700;
          font-size: 0.9rem;
        }
        .badge-high   { background: #238636; color: #fff; }
        .badge-medium { background: #d29922; color: #fff; }
        .badge-low    { background: #da3633; color: #fff; }
        .badge-auto   { background: #0d76db; color: #fff; }
        .badge-review { background: #d29922; color: #fff; }
        main { max-width: 1400px; margin: 0 auto; padding: 32px; }
        section { margin-bottom: 40px; }
        h2 { font-size: 1.15rem; font-weight: 600; margin-bottom: 12px; border-bottom: 1px solid #d0d7de; padding-bottom: 8px; }
        h3 { font-size: 1rem; font-weight: 600; margin: 16px 0 8px; }
        .summary-grid {
          display: grid;
          grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
          gap: 16px;
        }
        .summary-card {
          background: #fff;
          border: 1px solid #d0d7de;
          border-radius: 6px;
          padding: 16px;
          text-align: center;
        }
        .summary-card .number { font-size: 2rem; font-weight: 700; color: #0969da; }
        .summary-card .label  { font-size: 0.8rem; color: #57606a; margin-top: 4px; }
        table { border-collapse: collapse; width: 100%; background: #fff; border: 1px solid #d0d7de; border-radius: 6px; overflow: hidden; }
        th, td { padding: 10px 16px; text-align: left; border-bottom: 1px solid #d0d7de; }
        th { background: #f6f8fa; font-weight: 600; font-size: 0.85rem; color: #57606a; text-transform: uppercase; letter-spacing: 0.04em; }
        tr:last-child td { border-bottom: none; }
        .file-diff-block { margin-bottom: 32px; background: #fff; border: 1px solid #d0d7de; border-radius: 6px; overflow: hidden; }
        .file-diff-header {
          padding: 10px 16px;
          background: #f6f8fa;
          border-bottom: 1px solid #d0d7de;
          font-family: monospace;
          font-size: 0.85rem;
          display: flex;
          align-items: center;
          gap: 12px;
        }
        .file-diff-header .filename { font-weight: 600; flex: 1; }
        .file-diff-header .rules { font-size: 0.75rem; color: #57606a; }
        .unified-diff { display: none; }
        .d2h-wrapper { overflow-x: auto; }
        .no-diff { padding: 24px; color: #57606a; font-style: italic; text-align: center; }
        .manual-review-item { margin-bottom: 24px; background: #fff; border: 1px solid #ffa657; border-left: 4px solid #ffa657; border-radius: 6px; padding: 16px; }
        .manual-review-item.blocker { border-color: #da3633; border-left-color: #da3633; }
        .manual-review-item .item-header { display: flex; gap: 12px; align-items: flex-start; margin-bottom: 8px; }
        .manual-review-item .item-id { font-family: monospace; font-weight: 700; font-size: 0.85rem; }
        .manual-review-item .item-files { font-family: monospace; font-size: 0.8rem; color: #57606a; }
        code { background: #f6f8fa; border: 1px solid #d0d7de; border-radius: 4px; padding: 2px 6px; font-size: 0.85em; }
          </style>
        </head>
        <body>
          <header>
            <div>
              <h1>{$repoName} — Laravel {$fromVersion} → {$toVersion}</h1>
              <p class="meta">Generated: {$timestamp}</p>
            </div>
            <div>
              <span class="badge {$badgeClass}">&#x2713; {$score}% Confidence — {$labelEsc}</span>
            </div>
          </header>
          <main>
            <section id="summary">
              <h2>Summary</h2>
              {$summaryHtml}
            </section>
            <section id="breaking-changes">
              <h2>Breaking Changes Index</h2>
              {$breakingChangesHtml}
            </section>
            <section id="file-diffs">
              <h2>File Diffs</h2>
              {$diffsHtml}
            </section>
            <section id="manual-review">
              <h2>Manual Review Required</h2>
              {$manualReviewHtml}
            </section>
          </main>
          <script>
        {$diff2htmlJs}

        // Initialise Diff2Html on all .unified-diff elements
        (function () {
          var elements = document.querySelectorAll('.unified-diff');
          elements.forEach(function (pre) {
            var diffStr = pre.textContent || '';
            var wrapper = document.createElement('div');
            wrapper.className = 'd2h-wrapper';
            var html = Diff2Html.html(diffStr, {
              drawFileList: false,
              matching: 'lines',
              outputFormat: 'side-by-side',
              highlight: true,
            });
            wrapper.innerHTML = html;
            pre.parentNode.insertBefore(wrapper, pre);
          });
        }());
          </script>
        </body>
        </html>
        HTML;
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    private function buildSummary(ReportData $data, int $autoFixed, int $manualCount, int $blockerCount): string
    {
        $scanned = $data->totalFilesScanned;
        $changed = $data->totalFilesChanged;

        return <<<HTML
        <div class="summary-grid">
          <div class="summary-card"><div class="number">{$scanned}</div><div class="label">Files Scanned</div></div>
          <div class="summary-card"><div class="number">{$changed}</div><div class="label">Files Changed</div></div>
          <div class="summary-card"><div class="number">{$autoFixed}</div><div class="label">Auto-Fixed</div></div>
          <div class="summary-card"><div class="number">{$manualCount}</div><div class="label">Manual Review</div></div>
          <div class="summary-card"><div class="number">{$blockerCount}</div><div class="label">Dep. Blockers</div></div>
        </div>
        HTML;
    }

    private function buildBreakingChangesIndex(ReportData $data): string
    {
        if (empty($data->fileDiffs)) {
            return '<p class="no-diff">No file changes recorded.</p>';
        }

        // Collect unique rule IDs across all file diffs.
        /** @var array<string, int> $ruleFileCounts */
        $ruleFileCounts = [];
        foreach ($data->fileDiffs as $diff) {
            foreach ($diff['rules'] as $rule) {
                $ruleFileCounts[$rule] = ($ruleFileCounts[$rule] ?? 0) + 1;
            }
        }

        if (empty($ruleFileCounts)) {
            return '<p class="no-diff">No rule annotations recorded.</p>';
        }

        $rows = '';
        foreach ($ruleFileCounts as $ruleId => $fileCount) {
            $id   = $this->e($ruleId);
            $rows .= "<tr><td><code>{$id}</code></td><td><a href=\"#diff-{$id}\">{$fileCount} file(s)</a></td></tr>\n";
        }

        return "<table><thead><tr><th>Rule ID</th><th>Files Affected</th></tr></thead><tbody>{$rows}</tbody></table>";
    }

    private function buildDiffs(ReportData $data): string
    {
        if (empty($data->fileDiffs)) {
            return '<p class="no-diff">No diffs to display.</p>';
        }

        $html = '';
        foreach ($data->fileDiffs as $diffItem) {
            $file    = $this->e($diffItem['file']);
            $rules   = implode(', ', array_map(fn(string $r) => $this->e($r), $diffItem['rules']));
            $ruleIds = implode(' ', array_map(fn(string $r) => 'diff-' . $this->e($r), $diffItem['rules']));
            $diff    = htmlspecialchars($diffItem['diff'], ENT_NOQUOTES, 'UTF-8');

            $ruleAttr = $this->e(implode(',', $diffItem['rules']));
            $html .= <<<HTML
            <div class="file-diff-block" id="diff-{$file}">
              <div class="file-diff-header">
                <span class="filename">{$file}</span>
                <span class="rules">Rules: {$rules}</span>
              </div>
              <pre class="unified-diff" data-file="{$file}" data-rules="{$ruleAttr}">{$diff}</pre>
            </div>
            HTML;
        }

        return $html;
    }

    private function buildManualReview(ReportData $data): string
    {
        if (empty($data->manualReviewItems)) {
            return '<p>No manual review required. All changes were applied automatically.</p>';
        }

        $html = '';
        foreach ($data->manualReviewItems as $item) {
            $id     = $this->e($item['id']);
            $reason = $this->e($item['reason']);
            $files  = implode(', ', array_map(fn(string $f) => "<code>{$this->e($f)}</code>", $item['files']));

            $isBlocker   = stripos($item['id'], 'BLOCKER') !== false || stripos($item['reason'], 'incompatible') !== false;
            $extraClass  = $isBlocker ? ' blocker' : '';
            $badgeClass  = $isBlocker ? 'badge-low' : 'badge-review';
            $badgeLabel  = $isBlocker ? 'BLOCKER' : 'REVIEW';

            $html .= <<<HTML
            <div class="manual-review-item{$extraClass}">
              <div class="item-header">
                <span class="item-id">{$id}</span>
                <span class="badge {$badgeClass}">{$badgeLabel}</span>
              </div>
              <p>{$reason}</p>
              <p class="item-files">Affected: {$files}</p>
            </div>
            HTML;
        }

        return $html;
    }

    private function badgeClass(string $label): string
    {
        return match ($label) {
            'High'   => 'badge-high',
            'Medium' => 'badge-medium',
            default  => 'badge-low',
        };
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
