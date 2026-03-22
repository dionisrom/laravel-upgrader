<?php

declare(strict_types=1);

namespace Tests\Unit\Verification;

use AppContainer\Verification\PhpStanVerifier;
use AppContainer\Verification\VerificationContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class PhpStanVerifierTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/phpstan_verifier_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
    }

    public function testSkipsWhenSkipPhpStanIsTrue(): void
    {
        $verifier = new PhpStanVerifier($this->neverCalledFactory());
        $ctx      = new VerificationContext(
            workspacePath: $this->tmpDir,
            skipPhpStan:   true,
        );

        $result = $verifier->verify($this->tmpDir, $ctx);

        self::assertTrue($result->passed);
        self::assertSame(0, $result->issueCount);
    }

    public function testCreatesBaselineWhenItDoesNotExist(): void
    {
        $phpstanOutput = json_encode([
            'totals' => ['file_errors' => 3, 'other_errors' => 0],
            'files'  => [],
            'errors' => [],
        ]);

        $verifier     = new PhpStanVerifier($this->fakeFactory((string) $phpstanOutput, 0));
        $baselinePath = $this->tmpDir . '/baseline.json';
        $ctx          = new VerificationContext(
            workspacePath: $this->tmpDir,
            baselinePath:  $baselinePath,
        );

        $result = $verifier->verify($this->tmpDir, $ctx);

        self::assertTrue($result->passed);
        self::assertSame(0, $result->issueCount);
        self::assertFileExists($baselinePath);

        $baseline = json_decode((string) file_get_contents($baselinePath), true);
        self::assertSame(3, $baseline['error_count']);
    }

    public function testPassesWhenErrorCountDoesNotIncrease(): void
    {
        $baselinePath = $this->tmpDir . '/baseline.json';
        file_put_contents($baselinePath, json_encode(['error_count' => 5]));

        $phpstanOutput = json_encode([
            'totals' => ['file_errors' => 4, 'other_errors' => 0],
            'files'  => [],
            'errors' => [],
        ]);

        $verifier = new PhpStanVerifier($this->fakeFactory((string) $phpstanOutput, 0));
        $ctx      = new VerificationContext(
            workspacePath: $this->tmpDir,
            baselinePath:  $baselinePath,
        );

        $result = $verifier->verify($this->tmpDir, $ctx);

        self::assertTrue($result->passed);
        self::assertSame(0, $result->issueCount);
    }

    public function testFailsWhenErrorCountIncreases(): void
    {
        $baselinePath = $this->tmpDir . '/baseline.json';
        file_put_contents($baselinePath, json_encode(['error_count' => 2]));

        $phpstanOutput = json_encode([
            'totals' => ['file_errors' => 5, 'other_errors' => 1],
            'files'  => [],
            'errors' => [],
        ]);

        $verifier = new PhpStanVerifier($this->fakeFactory((string) $phpstanOutput, 0));
        $ctx      = new VerificationContext(
            workspacePath: $this->tmpDir,
            baselinePath:  $baselinePath,
        );

        $result = $verifier->verify($this->tmpDir, $ctx);

        self::assertFalse($result->passed);
        self::assertSame(1, $result->issueCount);
        self::assertStringContainsString('regression', $result->issues[0]->message);
        self::assertStringContainsString('2', $result->issues[0]->message);
        self::assertStringContainsString('6', $result->issues[0]->message);
    }

    public function testHandlesInvalidPhpStanJsonGracefully(): void
    {
        $verifier     = new PhpStanVerifier($this->fakeFactory('not-json', 0));
        $baselinePath = $this->tmpDir . '/baseline.json';
        $ctx          = new VerificationContext(
            workspacePath: $this->tmpDir,
            baselinePath:  $baselinePath,
        );

        $result = $verifier->verify($this->tmpDir, $ctx);

        self::assertTrue($result->passed);
        $baseline = json_decode((string) file_get_contents($baselinePath), true);
        self::assertSame(0, $baseline['error_count']);
    }

    /**
     * Returns a factory that creates a mock Process with fixed stdout and exit code.
     *
     * @return callable(list<string>, string): Process
     */
    private function fakeFactory(string $stdout, int $exitCode): callable
    {
        return function (array $cmd, string $cwd) use ($stdout, $exitCode): Process {
            $mock = $this->createMock(Process::class);
            $mock->method('run')->willReturn($exitCode);
            $mock->method('getExitCode')->willReturn($exitCode);
            $mock->method('getOutput')->willReturn($stdout);
            $mock->method('getErrorOutput')->willReturn('');

            return $mock;
        };
    }

    /**
     * Returns a factory that is never expected to be called.
     *
     * @return callable(list<string>, string): Process
     */
    private function neverCalledFactory(): callable
    {
        return function (array $cmd, string $cwd): Process {
            self::fail('Process factory should not be called when skipPhpStan is true');
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
