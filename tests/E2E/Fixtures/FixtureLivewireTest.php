<?php

declare(strict_types=1);

namespace Tests\E2E\Fixtures;

use PHPUnit\Framework\Attributes\Group;
use Tests\E2E\E2ETestCase;

#[Group('e2e')]
#[Group('integration')]
final class FixtureLivewireTest extends E2ETestCase
{
    public function testLivewireFixtureTriggersV3MigrationPath(): void
    {
        $this->requireDocker();

        $result = $this->runFixtureChain('fixture-livewire');

        $this->assertChainCompleted($result);
        $report = $this->assertUnifiedReportGenerated($result);
        $composer = $this->readComposerManifest($result->workspacePath);
        $contactForm = (string) file_get_contents($result->workspacePath . '/app/Http/Livewire/ContactForm.php');
        $dataTable = (string) file_get_contents($result->workspacePath . '/app/Http/Livewire/DataTable.php');
        $internalEmitter = (string) file_get_contents($result->workspacePath . '/app/Support/InternalEmitter.php');
        $changedFiles = $this->changedFilesFromReport($report);
        $expectedEmitCall = '$' . "this->emit('legacy.internal')";
        $this->assertFinalOutputPhpStanLevel6Passes($result->workspacePath);

        $this->assertComposerConstraintContains($composer, 'livewire/livewire', '3.0');
        self::assertStringNotContainsString('->emit(', $contactForm);
        self::assertStringContainsString('dispatch', $contactForm);
        self::assertStringContainsString('#[\\Livewire\\Attributes\\Computed]', $dataTable);
        self::assertStringContainsString('function visibleRowCount(): int', $dataTable);
        self::assertStringNotContainsString('function getVisibleRowCountProperty()', $dataTable);
        self::assertStringContainsString('dispatch', $dataTable);
        self::assertStringContainsString($expectedEmitCall, $internalEmitter);
        self::assertStringNotContainsString('$this->dispatch(', $internalEmitter);
        self::assertContains('app/Http/Livewire/ContactForm.php', $changedFiles);
        self::assertContains('app/Http/Livewire/DataTable.php', $changedFiles);
        self::assertNotContains('app/Support/InternalEmitter.php', $changedFiles);
        self::assertCount(5, $report['hops']);
    }
}