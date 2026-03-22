<?php

declare(strict_types=1);

namespace Tests\Unit\Verification;

use AppContainer\Verification\SyntaxVerifier;
use AppContainer\Verification\VerificationContext;
use PHPUnit\Framework\TestCase;

/**
 * SyntaxVerifier runs real `php -l` processes on temp files.
 * No Docker or mocking required — php is always available on the host.
 */
final class SyntaxVerifierTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/syntax_verifier_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
    }

    public function testPassesForValidPhpFile(): void
    {
        file_put_contents($this->tmpDir . '/valid.php', '<?php echo "hello";');

        $ctx    = $this->makeContext();
        $result = (new SyntaxVerifier())->verify($this->tmpDir, $ctx);

        self::assertTrue($result->passed);
        self::assertSame(0, $result->issueCount);
        self::assertSame('SyntaxVerifier', $result->verifierName);
        self::assertGreaterThanOrEqual(0.0, $result->durationSeconds);
    }

    public function testFailsForFileWithSyntaxError(): void
    {
        file_put_contents($this->tmpDir . '/broken.php', '<?php echo "unclosed string;');

        $ctx    = $this->makeContext();
        $result = (new SyntaxVerifier())->verify($this->tmpDir, $ctx);

        self::assertFalse($result->passed);
        self::assertGreaterThan(0, $result->issueCount);
        self::assertNotEmpty($result->issues);
        self::assertSame('error', $result->issues[0]->severity);
    }

    public function testCapturesLineNumberFromSyntaxError(): void
    {
        $code = "<?php\n\$a = 'unclosed;\n";
        file_put_contents($this->tmpDir . '/lined.php', $code);

        $ctx    = $this->makeContext();
        $result = (new SyntaxVerifier())->verify($this->tmpDir, $ctx);

        self::assertFalse($result->passed);
        self::assertGreaterThan(0, $result->issues[0]->line);
    }

    public function testPassesForEmptyWorkspace(): void
    {
        $ctx    = $this->makeContext();
        $result = (new SyntaxVerifier())->verify($this->tmpDir, $ctx);

        self::assertTrue($result->passed);
        self::assertSame(0, $result->issueCount);
    }

    public function testMixedFilesOnlyFailOnBroken(): void
    {
        file_put_contents($this->tmpDir . '/good.php', '<?php return true;');
        file_put_contents($this->tmpDir . '/bad.php', '<?php function(');

        $ctx    = $this->makeContext();
        $result = (new SyntaxVerifier())->verify($this->tmpDir, $ctx);

        self::assertFalse($result->passed);
        self::assertSame(1, $result->issueCount);
    }

    private function makeContext(): VerificationContext
    {
        return new VerificationContext(workspacePath: $this->tmpDir);
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
