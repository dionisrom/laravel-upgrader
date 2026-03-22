<?php

declare(strict_types=1);

namespace Tests\Unit\Verification;

use AppContainer\Verification\ComposerVerifier;
use AppContainer\Verification\VerificationContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ComposerVerifierTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/composer_verifier_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
    }

    public function testPassesWhenBothCommandsSucceed(): void
    {
        $calls    = 0;
        $factory  = $this->fakeSequentialFactory([
            ['exitCode' => 0, 'stdout' => '', 'stderr' => ''],
            ['exitCode' => 0, 'stdout' => '', 'stderr' => ''],
        ], $calls);

        $ctx    = new VerificationContext(workspacePath: $this->tmpDir);
        $result = (new ComposerVerifier($factory))->verify($this->tmpDir, $ctx);

        self::assertTrue($result->passed);
        self::assertSame(0, $result->issueCount);
        self::assertSame('ComposerVerifier', $result->verifierName);
        self::assertSame(2, $calls);
    }

    public function testFailsWhenValidateFails(): void
    {
        $calls   = 0;
        $factory = $this->fakeSequentialFactory([
            ['exitCode' => 1, 'stdout' => '', 'stderr' => 'composer.json is invalid'],
        ], $calls);

        $ctx    = new VerificationContext(workspacePath: $this->tmpDir);
        $result = (new ComposerVerifier($factory))->verify($this->tmpDir, $ctx);

        self::assertFalse($result->passed);
        self::assertSame(1, $result->issueCount);
        self::assertStringContainsString('composer.json is invalid', $result->issues[0]->message);
        self::assertSame(1, $calls);
    }

    public function testFailsWhenInstallFails(): void
    {
        $calls   = 0;
        $factory = $this->fakeSequentialFactory([
            ['exitCode' => 0, 'stdout' => '', 'stderr' => ''],
            ['exitCode' => 1, 'stdout' => '', 'stderr' => 'install failed'],
        ], $calls);

        $ctx    = new VerificationContext(workspacePath: $this->tmpDir);
        $result = (new ComposerVerifier($factory))->verify($this->tmpDir, $ctx);

        self::assertFalse($result->passed);
        self::assertSame(1, $result->issueCount);
        self::assertStringContainsString('install failed', $result->issues[0]->message);
        self::assertSame(2, $calls);
    }

    public function testUsesStdoutWhenStderrEmpty(): void
    {
        $calls   = 0;
        $factory = $this->fakeSequentialFactory([
            ['exitCode' => 1, 'stdout' => 'validation errors', 'stderr' => ''],
        ], $calls);

        $ctx    = new VerificationContext(workspacePath: $this->tmpDir);
        $result = (new ComposerVerifier($factory))->verify($this->tmpDir, $ctx);

        self::assertFalse($result->passed);
        self::assertStringContainsString('validation errors', $result->issues[0]->message);
    }

    /**
     * @param  list<array{exitCode: int, stdout: string, stderr: string}> $responses
     * @return callable(list<string>, string): Process
     */
    private function fakeSequentialFactory(array $responses, int &$calls): callable
    {
        return function (array $cmd, string $cwd) use ($responses, &$calls): Process {
            $response = $responses[$calls] ?? ['exitCode' => 0, 'stdout' => '', 'stderr' => ''];
            $calls++;

            $mock = $this->createMock(Process::class);
            $mock->method('run')->willReturn($response['exitCode']);
            $mock->method('getExitCode')->willReturn($response['exitCode']);
            $mock->method('getOutput')->willReturn($response['stdout']);
            $mock->method('getErrorOutput')->willReturn($response['stderr']);

            return $mock;
        };
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
