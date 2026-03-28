<?php

declare(strict_types=1);

namespace Tests\Unit\Rector;

use AppContainer\Rector\RectorExecutionException;
use AppContainer\Rector\RectorResult;
use PHPUnit\Framework\TestCase;

final class RectorResultTest extends TestCase
{
    public function test_from_json_parses_file_diffs(): void
    {
        $json = json_encode([
            'file_diffs' => [
                [
                    'file' => '/app/Models/User.php',
                    'diff' => '--- a/User.php\n+++ b/User.php',
                    'applied_rectors' => [
                        'RectorLaravel\\Rector\\SomeRule',
                    ],
                ],
            ],
            'errors' => [],
        ]);

        $result = RectorResult::fromJson($json);

        self::assertCount(1, $result->fileDiffs);
        self::assertSame('/app/Models/User.php', $result->fileDiffs[0]->file);
        self::assertSame(['RectorLaravel\\Rector\\SomeRule'], $result->fileDiffs[0]->appliedRectors);
        self::assertFalse($result->hasErrors());
        self::assertSame(1, $result->changedFileCount());
    }

    public function test_from_json_parses_errors(): void
    {
        $json = json_encode([
            'file_diffs' => [],
            'errors' => [
                [
                    'file' => '/app/broken.php',
                    'message' => 'Parse error',
                    'line' => 42,
                ],
            ],
        ]);

        $result = RectorResult::fromJson($json);

        self::assertCount(0, $result->fileDiffs);
        self::assertTrue($result->hasErrors());
        self::assertSame('Parse error', $result->errors[0]->message);
        self::assertSame(42, $result->errors[0]->line);
    }

    public function test_from_json_handles_empty_response(): void
    {
        $result = RectorResult::fromJson('{}');

        self::assertCount(0, $result->fileDiffs);
        self::assertCount(0, $result->errors);
    }

    public function test_from_json_throws_on_invalid_json(): void
    {
        $this->expectException(RectorExecutionException::class);
        $this->expectExceptionMessageMatches('/Failed to parse Rector JSON output/');

        RectorResult::fromJson('not json');
    }

    public function test_from_json_handles_missing_keys_gracefully(): void
    {
        $json = json_encode(['file_diffs' => 'invalid', 'errors' => 123]);

        $result = RectorResult::fromJson($json);

        self::assertCount(0, $result->fileDiffs);
        self::assertCount(0, $result->errors);
    }
}
