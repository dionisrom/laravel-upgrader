<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use App\Commands\DashboardCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class DashboardCommandTest extends TestCase
{
    private DashboardCommand $command;

    protected function setUp(): void
    {
        $this->command = new DashboardCommand();
    }

    public function testCommandHasCorrectName(): void
    {
        self::assertSame('dashboard', $this->command->getName());
    }

    public function testCommandHasPortOption(): void
    {
        self::assertTrue($this->command->getDefinition()->hasOption('port'));
    }

    public function testCommandHasNoBrowserOption(): void
    {
        self::assertTrue($this->command->getDefinition()->hasOption('no-browser'));
    }

    public function testInvalidPortReturnsExitCode2(): void
    {
        $application = new Application();
        $application->add($this->command);

        $tester = new CommandTester($this->command);
        $tester->execute(['--port' => 'abc'], ['interactive' => false]);

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('Invalid port', $tester->getDisplay());
    }

    public function testNegativePortReturnsExitCode2(): void
    {
        $application = new Application();
        $application->add($this->command);

        $tester = new CommandTester($this->command);
        $tester->execute(['--port' => '-1'], ['interactive' => false]);

        self::assertSame(2, $tester->getStatusCode());
    }

    public function testPortZeroReturnsExitCode2(): void
    {
        $application = new Application();
        $application->add($this->command);

        $tester = new CommandTester($this->command);
        $tester->execute(['--port' => '0'], ['interactive' => false]);

        self::assertSame(2, $tester->getStatusCode());
    }

    public function testPort99999ReturnsExitCode2(): void
    {
        $application = new Application();
        $application->add($this->command);

        $tester = new CommandTester($this->command);
        $tester->execute(['--port' => '99999'], ['interactive' => false]);

        self::assertSame(2, $tester->getStatusCode());
    }
}
