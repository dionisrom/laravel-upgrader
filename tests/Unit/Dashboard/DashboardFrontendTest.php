<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;

final class DashboardFrontendTest extends TestCase
{
    public function testFrontendNormalizesRuntimeStageNamesAndErrors(): void
    {
        $path = dirname(__DIR__, 3) . '/src/Dashboard/public/index.html';
        $html = file_get_contents($path);

        self::assertNotFalse($html);
        self::assertStringContainsString("inventoryscanner: 'inventory'", $html);
        self::assertStringContainsString("breakingchangeregistry: 'inventory'", $html);
        self::assertStringContainsString("rectorrunner: 'rector'", $html);
        self::assertStringContainsString("dependencyupgrader: 'composer'", $html);
        self::assertStringContainsString("case 'stage_error':", $html);
        self::assertStringContainsString("case 'verification_result':", $html);
        self::assertStringContainsString("case 'composer_install_failed':", $html);
        self::assertStringContainsString('Report Artifacts', $html);
        self::assertStringContainsString('Changed Files', $html);
        self::assertStringContainsString('loadReportSummary()', $html);
        self::assertStringContainsString("data.id || data.rule || '—'", $html);
    }
}