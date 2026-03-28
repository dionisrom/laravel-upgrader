<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use App\Commands\AnalyseCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class AnalyseCommandTest extends TestCase
{
    private AnalyseCommand $command;

    protected function setUp(): void
    {
        $this->command = new AnalyseCommand();
    }

    public function testCommandHasCorrectName(): void
    {
        self::assertSame('analyse', $this->command->getName());
    }

    public function testCommandHasRepoOption(): void
    {
        self::assertTrue($this->command->getDefinition()->hasOption('repo'));
    }

    public function testCommandHasToOption(): void
    {
        self::assertTrue($this->command->getDefinition()->hasOption('to'));
    }

    public function testCommandHasFromOption(): void
    {
        self::assertTrue($this->command->getDefinition()->hasOption('from'));
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

    public function testAnalyseCommandRoutesLumenRepositoryToDedicatedPipeline(): void
    {
        $application = new Application();
        $application->add($this->command);

        $workspace = $this->createLumenWorkspace();

        $tester = new CommandTester($this->command);
        $tester->execute(
            ['--repo' => $workspace, '--from' => '8', '--to' => '9'],
            ['interactive' => false],
        );

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Routing to the dedicated Lumen migration pipeline', $tester->getDisplay());
    }

    private function createLumenWorkspace(): string
    {
        $workspace = sys_get_temp_dir() . '/upgrader-analyse-lumen-' . uniqid('', true);
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
