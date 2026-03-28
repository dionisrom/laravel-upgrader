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
    // TRD-REG-002: Rector rule ↔ registry cross-check
    // -----------------------------------------------------------------------

    public function testEveryL8ToL9RectorRuleHasRegistryEntry(): void
    {
        $rulesDir = realpath(__DIR__ . '/../../src-container/Rector/Rules/L8ToL9');
        if ($rulesDir === false || !is_dir($rulesDir)) {
            $this->markTestSkipped('L8ToL9 rules directory not found');
        }

        $registry = BreakingChangeRegistry::load($this->validJson);
        $registeredRules = array_filter(
            array_column($registry->all(), 'rector_rule'),
            static fn(?string $r): bool => $r !== null
                && str_starts_with($r, 'AppContainer\\Rector\\Rules\\L8ToL9\\')
        );

        $ruleFiles = glob($rulesDir . '/*.php');
        $this->assertNotEmpty($ruleFiles, 'Expected at least one Rector rule file');

        foreach ($ruleFiles as $file) {
            $className = pathinfo($file, PATHINFO_FILENAME);
            $fqcn = 'AppContainer\\Rector\\Rules\\L8ToL9\\' . $className;
            $this->assertContains(
                $fqcn,
                $registeredRules,
                "TRD-REG-002: Rector rule {$fqcn} has no matching rector_rule entry in breaking-changes.json"
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
        $entry = $this->validEntry(['id' => 'duplicate_id']);
        $tmp = $this->writeTempJson(json_encode($this->validTopLevel([
            'breaking_changes' => [$entry, $entry],
        ])));
        $this->expectException(RegistryCorruptException::class);
        $this->expectExceptionMessageMatches('/duplicate/i');
        BreakingChangeRegistry::load($tmp);
    }

    public function testLoadThrowsOnInvalidSeverity(): void
    {
        $tmp = $this->writeTempJson(json_encode($this->validTopLevel([
            'breaking_changes' => [$this->validEntry(['severity' => 'critical'])],
        ])));
        $this->expectException(RegistryCorruptException::class);
        $this->expectExceptionMessageMatches('/severity/i');
        BreakingChangeRegistry::load($tmp);
    }

    public function testLoadThrowsOnInvalidCategory(): void
    {
        $tmp = $this->writeTempJson(json_encode($this->validTopLevel([
            'breaking_changes' => [$this->validEntry(['category' => 'invalid_category'])],
        ])));
        $this->expectException(RegistryCorruptException::class);
        $this->expectExceptionMessageMatches('/category/i');
        BreakingChangeRegistry::load($tmp);
    }

    public function testLoadThrowsOnMissingEntryRequiredField(): void
    {
        $entry = $this->validEntry();
        unset($entry['title']);
        $tmp = $this->writeTempJson(json_encode($this->validTopLevel([
            'breaking_changes' => [$entry],
        ])));
        $this->expectException(RegistryCorruptException::class);
        $this->expectExceptionMessageMatches('/title/');
        BreakingChangeRegistry::load($tmp);
    }

    public function testLoadThrowsOnMissingTopLevelPhpMinimum(): void
    {
        $data = $this->validTopLevel();
        unset($data['php_minimum']);
        $tmp = $this->writeTempJson(json_encode($data));
        $this->expectException(RegistryCorruptException::class);
        $this->expectExceptionMessageMatches('/php_minimum/');
        BreakingChangeRegistry::load($tmp);
    }

    public function testLoadThrowsOnMissingTopLevelLastCurated(): void
    {
        $data = $this->validTopLevel();
        unset($data['last_curated']);
        $tmp = $this->writeTempJson(json_encode($data));
        $this->expectException(RegistryCorruptException::class);
        $this->expectExceptionMessageMatches('/last_curated/');
        BreakingChangeRegistry::load($tmp);
    }

    public function testLoadThrowsOnMissingDescription(): void
    {
        $entry = $this->validEntry();
        unset($entry['description']);
        $tmp = $this->writeTempJson(json_encode($this->validTopLevel([
            'breaking_changes' => [$entry],
        ])));
        $this->expectException(RegistryCorruptException::class);
        $this->expectExceptionMessageMatches('/description/');
        BreakingChangeRegistry::load($tmp);
    }

    public function testLoadThrowsOnMissingAffectsLumen(): void
    {
        $entry = $this->validEntry();
        unset($entry['affects_lumen']);
        $tmp = $this->writeTempJson(json_encode($this->validTopLevel([
            'breaking_changes' => [$entry],
        ])));
        $this->expectException(RegistryCorruptException::class);
        $this->expectExceptionMessageMatches('/affects_lumen/');
        BreakingChangeRegistry::load($tmp);
    }

    public function testLoadThrowsOnMissingManualReviewRequired(): void
    {
        $entry = $this->validEntry();
        unset($entry['manual_review_required']);
        $tmp = $this->writeTempJson(json_encode($this->validTopLevel([
            'breaking_changes' => [$entry],
        ])));
        $this->expectException(RegistryCorruptException::class);
        $this->expectExceptionMessageMatches('/manual_review_required/');
        BreakingChangeRegistry::load($tmp);
    }

    public function testLoadThrowsOnMissingOfficialDocAnchor(): void
    {
        $entry = $this->validEntry();
        unset($entry['official_doc_anchor']);
        $tmp = $this->writeTempJson(json_encode($this->validTopLevel([
            'breaking_changes' => [$entry],
        ])));
        $this->expectException(RegistryCorruptException::class);
        $this->expectExceptionMessageMatches('/official_doc_anchor/');
        BreakingChangeRegistry::load($tmp);
    }

    public function testLoadThrowsOnMissingRectorRule(): void
    {
        $entry = $this->validEntry();
        unset($entry['rector_rule']);
        $tmp = $this->writeTempJson(json_encode($this->validTopLevel([
            'breaking_changes' => [$entry],
        ])));
        $this->expectException(RegistryCorruptException::class);
        $this->expectExceptionMessageMatches('/rector_rule/');
        BreakingChangeRegistry::load($tmp);
    }

    public function testLoadThrowsOnMissingMigrationExample(): void
    {
        $entry = $this->validEntry();
        unset($entry['migration_example']);
        $tmp = $this->writeTempJson(json_encode($this->validTopLevel([
            'breaking_changes' => [$entry],
        ])));
        $this->expectException(RegistryCorruptException::class);
        $this->expectExceptionMessageMatches('/migration_example/');
        BreakingChangeRegistry::load($tmp);
    }

    public function testLoadThrowsOnMigrationExampleMissingBefore(): void
    {
        $entry = $this->validEntry(['migration_example' => ['after' => '// new']]);
        $tmp = $this->writeTempJson(json_encode($this->validTopLevel([
            'breaking_changes' => [$entry],
        ])));
        $this->expectException(RegistryCorruptException::class);
        $this->expectExceptionMessageMatches('/before/');
        BreakingChangeRegistry::load($tmp);
    }

    public function testLoadThrowsOnMigrationExampleMissingAfter(): void
    {
        $entry = $this->validEntry(['migration_example' => ['before' => '// old']]);
        $tmp = $this->writeTempJson(json_encode($this->validTopLevel([
            'breaking_changes' => [$entry],
        ])));
        $this->expectException(RegistryCorruptException::class);
        $this->expectExceptionMessageMatches('/after/');
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

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function validTopLevel(array $overrides = []): array
    {
        return array_merge([
            'hop' => '8_to_9',
            'laravel_from' => '8.x',
            'laravel_to' => '9.x',
            'php_minimum' => '8.0',
            'last_curated' => '2026-03-21',
            'breaking_changes' => [],
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function validEntry(array $overrides = []): array
    {
        return array_merge([
            'id' => 'test_entry',
            'severity' => 'high',
            'category' => 'eloquent',
            'title' => 'Test Entry',
            'description' => 'A test breaking change.',
            'rector_rule' => null,
            'automated' => false,
            'affects_lumen' => false,
            'manual_review_required' => false,
            'migration_example' => ['before' => '// old', 'after' => '// new'],
            'official_doc_anchor' => 'https://laravel.com/docs/9.x/upgrade#test',
        ], $overrides);
    }
}
