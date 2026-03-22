<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator\Events;

use App\Orchestrator\Events\EventCatalogue;
use PHPUnit\Framework\TestCase;

final class EventCatalogueTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function getAllConstants(): array
    {
        $reflection = new \ReflectionClass(EventCatalogue::class);
        /** @var array<string, string> $constants */
        $constants = $reflection->getConstants();

        return array_values($constants);
    }

    public function testAllRequiredConstantsExist(): void
    {
        $required = [
            'PIPELINE_START',
            'STAGE_START',
            'STAGE_COMPLETE',
            'FILE_CHANGED',
            'CHECKPOINT_WRITTEN',
            'BREAKING_CHANGE_APPLIED',
            'MANUAL_REVIEW_REQUIRED',
            'DEPENDENCY_BLOCKER',
            'VERIFICATION_RESULT',
            'PHPSTAN_REGRESSION',
            'HOP_COMPLETE',
            'PIPELINE_ERROR',
            'WARNING',
            'STDERR',
            'HOP_SKIPPED',
        ];

        $reflection = new \ReflectionClass(EventCatalogue::class);

        foreach ($required as $name) {
            self::assertTrue(
                $reflection->hasConstant($name),
                "EventCatalogue missing constant: {$name}",
            );

            $value = $reflection->getConstant($name);
            self::assertIsString($value, "Constant {$name} is not a string");
            self::assertNotEmpty($value, "Constant {$name} is empty");
        }
    }

    public function testConstantValuesAreUnique(): void
    {
        $values = $this->getAllConstants();

        $unique = array_unique($values);

        self::assertCount(
            count($values),
            $unique,
            'EventCatalogue has duplicate constant values: ' . implode(', ', array_diff_assoc($values, $unique)),
        );
    }
}
