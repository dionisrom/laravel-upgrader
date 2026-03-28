<?php

declare(strict_types=1);

namespace Tests\Unit\Report;

use AppContainer\Report\ConfidenceScorer;
use AppContainer\Report\ReportData;
use PHPUnit\Framework\TestCase;

final class ConfidenceScorerTest extends TestCase
{
    private ConfidenceScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scorer = new ConfidenceScorer();
    }

    public function testBaseScoreIsHundred(): void
    {
        $data = $this->makeData();
        $this->assertSame(100, $this->scorer->score($data));
    }

    public function testManualReviewDeductsPoints(): void
    {
        // 3 files with manual review → -6
        $items = [];
        for ($i = 1; $i <= 3; $i++) {
            $items[] = ['id' => "RULE-{$i}", 'automated' => false, 'reason' => 'Needs review', 'files' => ["file{$i}.php"]];
        }
        $data = $this->makeData(manualReviewItems: $items);
        $this->assertSame(94, $this->scorer->score($data));
    }

    public function testManualReviewDeductionCapsAt30(): void
    {
        // 20 files → would be -40, but capped at -30
        $items = [];
        for ($i = 1; $i <= 20; $i++) {
            $items[] = ['id' => "RULE-{$i}", 'automated' => false, 'reason' => 'Needs review', 'files' => ["file{$i}.php"]];
        }
        $data = $this->makeData(manualReviewItems: $items);
        $this->assertSame(70, $this->scorer->score($data));
    }

    public function testDependencyBlockerDeducts(): void
    {
        $blockers = [
            ['package' => 'acme/pkg', 'current_version' => '1.0', 'severity' => 'high'],
        ];
        $data = $this->makeData(dependencyBlockers: $blockers);
        $this->assertSame(90, $this->scorer->score($data));
    }

    public function testPhpstanRegressionDeducts(): void
    {
        $regressions = [
            ['before_count' => 0, 'after_count' => 5, 'new_errors' => ['error1']],
        ];
        $data = $this->makeData(phpstanRegressions: $regressions);
        $this->assertSame(85, $this->scorer->score($data));
    }

    public function testSyntaxErrorAlwaysZero(): void
    {
        $data = $this->makeData(hasSyntaxError: true);
        $this->assertSame(0, $this->scorer->score($data));
    }

    public function testSyntaxErrorOverridesAllOtherFactors(): void
    {
        // Even with no other issues, syntax error forces 0.
        $data = $this->makeData(hasSyntaxError: true);
        $this->assertSame(0, $this->scorer->score($data));
    }

    public function testScoreFloorIsZero(): void
    {
        // 10 blockers (-100) + 3 regressions (-45) → would be -45, floored at 0
        $blockers    = array_fill(0, 10, ['package' => 'x', 'current_version' => '1', 'severity' => 'high']);
        $regressions = array_fill(0, 3, ['before_count' => 0, 'after_count' => 1, 'new_errors' => []]);
        $data = $this->makeData(dependencyBlockers: $blockers, phpstanRegressions: $regressions);
        $this->assertSame(0, $this->scorer->score($data));
    }

    public function testLabelHighMediumLow(): void
    {
        $this->assertSame('High',   $this->scorer->label(100));
        $this->assertSame('High',   $this->scorer->label(80));
        $this->assertSame('Medium', $this->scorer->label(79));
        $this->assertSame('Medium', $this->scorer->label(50));
        $this->assertSame('Low',    $this->scorer->label(49));
        $this->assertSame('Low',    $this->scorer->label(0));
    }

    public function testFileScoreHighForCleanFile(): void
    {
        $data = $this->makeData();
        $this->assertSame(100, $this->scorer->fileScore('app/Clean.php', $data));
        $this->assertSame('High', $this->scorer->label($this->scorer->fileScore('app/Clean.php', $data)));
    }

    public function testFileScoreLowForManualReviewFile(): void
    {
        $items = [
            ['id' => 'RULE-1', 'automated' => false, 'reason' => 'Needs review', 'files' => ['app/Foo.php']],
        ];
        $data = $this->makeData(manualReviewItems: $items);
        $score = $this->scorer->fileScore('app/Foo.php', $data);
        $this->assertSame(40, $score);
        $this->assertSame('Low', $this->scorer->label($score));
    }

    public function testFileScoreZeroOnSyntaxError(): void
    {
        $data = $this->makeData(hasSyntaxError: true);
        $this->assertSame(0, $this->scorer->fileScore('app/Any.php', $data));
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * @param list<array{id: string, automated: bool, reason: string, files: list<string>}> $manualReviewItems
     * @param list<array{package: string, current_version: string, severity: string}> $dependencyBlockers
     * @param list<array{before_count: int, after_count: int, new_errors: list<string>}> $phpstanRegressions
     */
    private function makeData(
        array $manualReviewItems  = [],
        array $dependencyBlockers = [],
        array $phpstanRegressions = [],
        bool  $hasSyntaxError     = false,
    ): ReportData {
        return new ReportData(
            repoName:           'acme/app',
            fromVersion:        '8',
            toVersion:          '9',
            runId:              'test-run-001',
            repoSha:            'abc123',
            hostVersion:        '1.0.0',
            timestamp:          '2024-01-01T00:00:00Z',
            fileDiffs:          [],
            manualReviewItems:  $manualReviewItems,
            dependencyBlockers: $dependencyBlockers,
            phpstanRegressions: $phpstanRegressions,
            verificationResults: [],
            auditEvents:        [],
            hasSyntaxError:     $hasSyntaxError,
            totalFilesScanned:  10,
            totalFilesChanged:  0,
        );
    }
}
