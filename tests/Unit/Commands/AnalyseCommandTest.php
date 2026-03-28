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
}
