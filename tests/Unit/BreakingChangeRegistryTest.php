<?php

declare(strict_types=1);

namespace Tests\Unit;

use AppContainer\BreakingChangeRegistry;
use AppContainer\Exception\RegistryCorruptException;
use PHPUnit\Framework\TestCase;

class BreakingChangeRegistryTest extends TestCase
{
    private string $validJson;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validJson = realpath(__DIR__ . '/../../docker/hop-8-to-9/docs/breaking-changes.json');
    }

    // -----------------------------------------------------------------------
    // load() — happy path
    // -----------------------------------------------------------------------

    public function testLoadReturnsRegistryInstance(): void
    {
        $registry = BreakingChangeRegistry::load($this->validJson);
        $this->assertInstanceOf(BreakingChangeRegistry::class, $registry);
    }

    public function testAllReturnsNonEmptyArray(): void
    {
        $registry = BreakingChangeRegistry::load($this->validJson);
        $all = $registry->all();
        $this->assertNotEmpty($all, 'breaking-changes.json should contain at least one entry');
    }

    public function testAllContainsAtLeast20Entries(): void
    {
        $registry = BreakingChangeRegistry::load($this->validJson);
        $this->assertGreaterThanOrEqual(
            20,
            count($registry->all()),
            'Breaking change registry must document at least 20 L8→L9 changes'
        );
    }

    // -----------------------------------------------------------------------
    // ID uniqueness
    // -----------------------------------------------------------------------

    public function testAllIdsAreUnique(): void
    {
        $registry = BreakingChangeRegistry::load($this->validJson);
        $ids = array_column($registry->all(), 'id');
        $unique = array_unique($ids);
        $this->assertCount(
            count($ids),
            $unique,
            'All breaking change IDs must be unique; duplicates found: '
            . implode(', ', array_diff_assoc($ids, $unique))
        );
    }

    // -----------------------------------------------------------------------
    // Filter methods
    // -----------------------------------------------------------------------

    public function testByCategoryFiltersCorrectly(): void
    {
        $registry = BreakingChangeRegistry::load($this->validJson);
        $eloquent = $registry->byCategory('eloquent');
        foreach ($eloquent as $entry) {
            $this->assertSame('eloquent', $entry['category']);
        }
        $this->assertNotEmpty($eloquent, 'Expected at least one eloquent entry');
    }

    public function testBySeverityFiltersCorrectly(): void
    {
        $registry = BreakingChangeRegistry::load($this->validJson);
        $high = $registry->bySeverity('high');
        foreach ($high as $entry) {
            $this->assertSame('high', $entry['severity']);
        }
        $this->assertNotEmpty($high, 'Expected at least one high-severity entry');
    }

    public function testBlockerSeverityExists(): void
    {
        $registry = BreakingChangeRegistry::load($this->validJson);
        $blockers = $registry->bySeverity('blocker');
        $this->assertNotEmpty($blockers, 'PHP 8.0 minimum should be listed as a blocker');
    }

    public function testAutomatedFiltersToOnlyAutomatedEntries(): void
    {
        $registry = BreakingChangeRegistry::load($this->validJson);
        $automated = $registry->automated();
        foreach ($automated as $entry) {
            $this->assertTrue($entry['automated'], "Entry {$entry['id']} should have automated=true");
        }
    }

    public function testManualReviewFiltersCorrectly(): void
    {
        $registry = BreakingChangeRegistry::load($this->validJson);
        $manual = $registry->manualReview();
        foreach ($manual as $entry) {
            $this->assertTrue(
                $entry['manual_review_required'],
                "Entry {$entry['id']} should have manual_review_required=true"
            );
        }
        $this->assertNotEmpty($manual, 'Expected at least one manual review entry');
    }

    public function testManualOnlyEntriesHaveNullRectorRule(): void
    {
        $registry = BreakingChangeRegistry::load($this->validJson);
        $manual = $registry->manualReview();
        foreach ($manual as $entry) {
            if (!$entry['automated']) {
                $this->assertNull(
                    $entry['rector_rule'] ?? null,
                    "Manual-only entry {$entry['id']} should have rector_rule=null"
                );
            }
        }
    }

    // -----------------------------------------------------------------------
    // Required fields on every entry
    // -----------------------------------------------------------------------

    public function testEveryEntryHasRequiredFields(): void
    {
        $required = [
            'id', 'severity', 'category', 'title', 'description',
            'automated', 'affects_lumen', 'manual_review_required',
            'migration_example', 'official_doc_anchor',
        ];

        $registry = BreakingChangeRegistry::load($this->validJson);
        foreach ($registry->all() as $entry) {
            foreach ($required as $field) {
                $this->assertArrayHasKey(
                    $field,
                    $entry,
                    "Entry '{$entry['id']}' is missing required field '{$field}'"
                );
            }
            $this->assertArrayHasKey(
                'before',
                $entry['migration_example'],
                "Entry '{$entry['id']}' migration_example missing 'before'"
            );
            $this->assertArrayHasKey(
                'after',
                $entry['migration_example'],
                "Entry '{$entry['id']}' migration_example missing 'after'"
            );
        }
    }

    // -----------------------------------------------------------------------
    // load() — failure cases
    // -----------------------------------------------------------------------

    public function testLoadThrowsOnMissingFile(): void
    {
        $this->expectException(RegistryCorruptException::class);
        $this->expectExceptionMessageMatches('/not found/i');
        BreakingChangeRegistry::load('/nonexistent/path/breaking-changes.json');
    }

    public function testLoadThrowsOnInvalidJson(): void
    {
        $tmp = $this->writeTempJson('{ invalid json }');
        $this->expectException(RegistryCorruptException::class);
        $this->expectExceptionMessageMatches('/invalid json/i');
        BreakingChangeRegistry::load($tmp);
    }

    public function testLoadThrowsOnMissingRequiredTopLevelKey(): void
    {
        $tmp = $this->writeTempJson(json_encode([
            'hop' => '8_to_9',
            'laravel_from' => '8.x',
            // 'laravel_to' intentionally missing
            'breaking_changes' => [],
        ]));
        $this->expectException(RegistryCorruptException::class);
        $this->expectExceptionMessageMatches('/laravel_to/');
        BreakingChangeRegistry::load($tmp);
    }

    public function testLoadThrowsOnDuplicateId(): void
    {
        $entry = [
            'id' => 'duplicate_id',
            'severity' => 'high',
            'category' => 'eloquent',
            'title' => 'Duplicate',
            'automated' => false,
        ];
        $tmp = $this->writeTempJson(json_encode([
            'hop' => '8_to_9',
            'laravel_from' => '8.x',
            'laravel_to' => '9.x',
            'breaking_changes' => [$entry, $entry],
        ]));
        $this->expectException(RegistryCorruptException::class);
        $this->expectExceptionMessageMatches('/duplicate/i');
        BreakingChangeRegistry::load($tmp);
    }

    public function testLoadThrowsOnInvalidSeverity(): void
    {
        $tmp = $this->writeTempJson(json_encode([
            'hop' => '8_to_9',
            'laravel_from' => '8.x',
            'laravel_to' => '9.x',
            'breaking_changes' => [[
                'id' => 'test_entry',
                'severity' => 'critical', // invalid
                'category' => 'eloquent',
                'title' => 'Test',
                'automated' => false,
            ]],
        ]));
        $this->expectException(RegistryCorruptException::class);
        $this->expectExceptionMessageMatches('/severity/i');
        BreakingChangeRegistry::load($tmp);
    }

    public function testLoadThrowsOnInvalidCategory(): void
    {
        $tmp = $this->writeTempJson(json_encode([
            'hop' => '8_to_9',
            'laravel_from' => '8.x',
            'laravel_to' => '9.x',
            'breaking_changes' => [[
                'id' => 'test_entry',
                'severity' => 'high',
                'category' => 'invalid_category', // invalid
                'title' => 'Test',
                'automated' => false,
            ]],
        ]));
        $this->expectException(RegistryCorruptException::class);
        $this->expectExceptionMessageMatches('/category/i');
        BreakingChangeRegistry::load($tmp);
    }

    public function testLoadThrowsOnMissingEntryRequiredField(): void
    {
        $tmp = $this->writeTempJson(json_encode([
            'hop' => '8_to_9',
            'laravel_from' => '8.x',
            'laravel_to' => '9.x',
            'breaking_changes' => [[
                'id' => 'test_entry',
                'severity' => 'high',
                'category' => 'eloquent',
                // 'title' intentionally missing
                'automated' => false,
            ]],
        ]));
        $this->expectException(RegistryCorruptException::class);
        $this->expectExceptionMessageMatches('/title/');
        BreakingChangeRegistry::load($tmp);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function writeTempJson(string $content): string
    {
        $path = sys_get_temp_dir() . '/bcr_test_' . uniqid() . '.json';
        file_put_contents($path, $content);
        return $path;
    }
}
