<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class CliBootstrapTest extends TestCase
{
    public function testCliHelpRendersSuccessfully(): void
    {
        $process = new Process([PHP_BINARY, $this->repoRoot() . '/bin/upgrader', '--help'], $this->repoRoot());
        $process->setTimeout(30);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $output = $process->getOutput();
        self::assertStringContainsString('Description:', $output);
        self::assertStringContainsString('List commands', $output);
        self::assertStringContainsString('bin/upgrader list', $output);
    }

    public function testCliListContainsExpectedCommands(): void
    {
        $process = new Process([PHP_BINARY, $this->repoRoot() . '/bin/upgrader', 'list', '--raw'], $this->repoRoot());
        $process->setTimeout(30);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $output = $process->getOutput();
        self::assertStringContainsString('run', $output);
        self::assertStringContainsString('analyse', $output);
        self::assertStringContainsString('dashboard', $output);
        self::assertStringContainsString('version', $output);
    }

    public function testComposerScriptsMatchScaffoldContract(): void
    {
        $composer = $this->composerConfig();

        self::assertSame('phpunit --configuration phpunit.xml.dist --testsuite unit --no-coverage', $composer['scripts']['test']);
        self::assertSame('phpunit --configuration phpunit.xml.dist --testsuite integration --no-coverage', $composer['scripts']['test:integration']);
        self::assertSame('phpstan analyse --no-progress --memory-limit=512M', $composer['scripts']['phpstan']);
        self::assertSame('phpcs --standard=PSR12 src src-container', $composer['scripts']['cs-check']);
    }

    public function testComposerAutoloadMappingsMatchScaffoldContract(): void
    {
        $composer = $this->composerConfig();

        self::assertSame('src/', $composer['autoload']['psr-4']['App\\']);
        self::assertSame('src-container/', $composer['autoload']['psr-4']['AppContainer\\']);
    }

    public function testPhpunitConfigurationDeclaresUnitAndIntegrationSuites(): void
    {
        $xml = simplexml_load_file($this->repoRoot() . '/phpunit.xml.dist');
        self::assertNotFalse($xml);

        $suiteNames = [];
        foreach ($xml->testsuites->testsuite as $testsuite) {
            $suiteNames[] = (string) $testsuite['name'];
        }

        self::assertContains('unit', $suiteNames);
        self::assertContains('integration', $suiteNames);
    }

    public function testCodingStandardConfigurationExists(): void
    {
        $hasPhpCsFixer = is_file($this->repoRoot() . '/.php-cs-fixer.dist.php');
        $hasPhpcs = is_file($this->repoRoot() . '/phpcs.xml.dist');

        self::assertTrue($hasPhpCsFixer || $hasPhpcs, 'Expected .php-cs-fixer.dist.php or phpcs.xml.dist to exist.');
    }

    public function testPhpstanUsesSingleSourceOfTruth(): void
    {
        $phpstan = file_get_contents($this->repoRoot() . '/phpstan.neon');
        self::assertNotFalse($phpstan);
        self::assertMatchesRegularExpression('/^\s*level:\s*6\s*$/m', $phpstan);

        $composer = $this->composerConfig();
        self::assertStringNotContainsString('--level', $composer['scripts']['phpstan']);
    }

    /**
     * @return array<string, mixed>
     */
    private function composerConfig(): array
    {
        $json = file_get_contents($this->repoRoot() . '/composer.json');
        self::assertNotFalse($json);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}