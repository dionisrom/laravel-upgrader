<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use App\Commands\RunCommand;
use App\Commands\VersionCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class VersionCommandTest extends TestCase
{
    public function testCommandHasCorrectName(): void
    {
        $command = new VersionCommand();
        self::assertSame('version', $command->getName());
    }

    public function testOutputContainsVersionString(): void
    {
        $command = new VersionCommand();
        $application = new Application();
        $application->add($command);

        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString(RunCommand::VERSION, $tester->getDisplay());
        self::assertStringContainsString('Laravel Enterprise Upgrader', $tester->getDisplay());
    }

    public function testOutputContainsPhpVersion(): void
    {
        $command = new VersionCommand();
        $application = new Application();
        $application->add($command);

        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertStringContainsString(PHP_VERSION, $tester->getDisplay());
    }
}
