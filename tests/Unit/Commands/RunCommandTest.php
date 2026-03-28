<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use App\Commands\RunCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class RunCommandTest extends TestCase
{
    private RunCommand $command;

    protected function setUp(): void
    {
        $this->command = new RunCommand();
    }

    public function testCommandHasCorrectName(): void
    {
        self::assertSame('run', $this->command->getName());
    }

    public function testCommandHasRepoOption(): void
    {
        self::assertTrue($this->command->getDefinition()->hasOption('repo'));
    }

    public function testCommandHasDryRunOption(): void
    {
        self::assertTrue($this->command->getDefinition()->hasOption('dry-run'));
    }

    public function testCommandExitsInvalidWhenRepoMissing(): void
    {
        $application = new Application();
        $application->add($this->command);

        $tester = new CommandTester($this->command);
        $tester->execute(['--to' => '9'], ['interactive' => false]);

        self::assertSame(2, $tester->getStatusCode());
    }

    public function testCommandExitsInvalidWhenRepoIsInvalidPath(): void
    {
        $application = new Application();
        $application->add($this->command);

        $tester = new CommandTester($this->command);
        $tester->execute(
            ['--repo' => '/nonexistent/path/12345', '--to' => '9'],
            ['interactive' => false],
        );

        self::assertSame(2, $tester->getStatusCode());
    }

    public function testSkipPhpstanNoInteractionDoesNotPrompt(): void
    {
        $application = new Application();
        $application->add($this->command);

        $tester = new CommandTester($this->command);
        // Repo is invalid so it will exit code 2 for validation, but the point
        // is that --skip-phpstan does NOT trigger the confirmation prompt in
        // non-interactive mode.
        $tester->execute(
            ['--repo' => '/nonexistent/path/12345', '--to' => '9', '--skip-phpstan' => true],
            ['interactive' => false],
        );

        self::assertStringNotContainsString('I understand PHPStan will not run', $tester->getDisplay());
        self::assertSame(2, $tester->getStatusCode());
    }

    public function testSkipPhpstanInteractiveShowsPrompt(): void
    {
        $application = new Application();
        $application->add($this->command);

        $tester = new CommandTester($this->command);
        // Provide wrong confirmation text via setInputs — should abort with exit 2
        $tester->setInputs(['wrong answer']);
        $tester->execute(
            ['--repo' => __DIR__, '--to' => '9', '--skip-phpstan' => true],
            ['interactive' => true],
        );

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('Confirmation not matched', $tester->getDisplay());
    }

    public function testRunCommandRoutesLumenRepositoryToDedicatedPipeline(): void
    {
        $application = new Application();
        $application->add($this->command);

        $workspace = $this->createLumenWorkspace();

        $tester = new CommandTester($this->command);
        $tester->execute(
            ['--repo' => $workspace, '--from' => '8', '--to' => '9', '--dry-run' => true],
            ['interactive' => false],
        );

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Routing to the dedicated Lumen migration pipeline', $tester->getDisplay());
    }

    public function testCopyReportArtifactsCopiesWorkspaceReportsIntoOutputDirectory(): void
    {
        $workspace = sys_get_temp_dir() . '/upgrader-run-report-workspace-' . uniqid('', true);
        $output = sys_get_temp_dir() . '/upgrader-run-report-output-' . uniqid('', true);
        mkdir($workspace . '/.upgrader', 0755, true);
        mkdir($output, 0755, true);

        file_put_contents($workspace . '/.upgrader/report.json', '{"ok":true}');
        file_put_contents($workspace . '/manual-review.md', '# Manual');

        $method = new \ReflectionMethod($this->command, 'copyReportArtifacts');
        $method->setAccessible(true);
        $method->invoke($this->command, $workspace, $output);

        self::assertFileExists($output . '/report.json');
        self::assertFileExists($output . '/manual-review.md');
    }

    private function createLumenWorkspace(): string
    {
        $workspace = sys_get_temp_dir() . '/upgrader-run-lumen-' . uniqid('', true);
        mkdir($workspace, 0755, true);
        mkdir($workspace . '/bootstrap', 0755, true);

        file_put_contents(
            $workspace . '/composer.json',
            json_encode(['require' => ['laravel/lumen-framework' => '^8.0']], JSON_THROW_ON_ERROR),
        );
        file_put_contents(
            $workspace . '/bootstrap/app.php',
            "<?php\n\$app = new Laravel\\Lumen\\Application(dirname(__DIR__));\nreturn \$app;\n",
        );

        return $workspace;
    }
}
