<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Orchestrator\AuditLogWriter;
use PHPUnit\Framework\TestCase;

final class AuditLogWriterTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        $this->logFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'audit-test-' . bin2hex(random_bytes(4)) . '.json';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->logFile)) {
            @unlink($this->logFile);
        }
    }

    public function testEventIsEnrichedWithRunMetadata(): void
    {
        $writer = new AuditLogWriter($this->logFile, 'run-123', 'abc123', '1.0.0');
        $writer->consume(['event' => 'stage_start', 'stage' => 'rector']);

        $lines = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertCount(1, $lines);

        $decoded = json_decode($lines[0], true);
        self::assertSame('run-123', $decoded['run_id']);
        self::assertSame('abc123', $decoded['repo_sha']);
        self::assertSame('1.0.0', $decoded['host_version']);
        self::assertArrayHasKey('host_ts', $decoded);
        self::assertSame('stage_start', $decoded['event']);
    }

    public function testSensitiveFieldsAreStripped(): void
    {
        $writer = new AuditLogWriter($this->logFile, 'run-1', 'sha-1', '1.0.0');
        $writer->consume([
            'event' => 'test',
            'token' => 'ghp_secret123',
            'password' => 'hunter2',
            'secret' => 'shh',
            'api_key' => 'ak_12345',
            'secret_key' => 'sk_12345',
            'private_key' => 'pk_12345',
            'auth_key' => 'authk_12345',
            'source_code' => '<?php echo 1;',
            'file_contents' => 'data',
            'content' => 'body',
            'key' => 'legitimate-field',
            'safe_field' => 'kept',
        ]);

        $lines = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $decoded = json_decode($lines[0], true);

        self::assertArrayNotHasKey('token', $decoded);
        self::assertArrayNotHasKey('password', $decoded);
        self::assertArrayNotHasKey('secret', $decoded);
        self::assertArrayNotHasKey('api_key', $decoded);
        self::assertArrayNotHasKey('secret_key', $decoded);
        self::assertArrayNotHasKey('private_key', $decoded);
        self::assertArrayNotHasKey('auth_key', $decoded);
        self::assertArrayNotHasKey('source_code', $decoded);
        self::assertArrayNotHasKey('file_contents', $decoded);
        self::assertArrayNotHasKey('content', $decoded);
        self::assertSame('legitimate-field', $decoded['key']);
        self::assertSame('kept', $decoded['safe_field']);
    }

    public function testOutputIsValidJsonNd(): void
    {
        $writer = new AuditLogWriter($this->logFile, 'run-1', 'sha-1', '1.0.0');
        $writer->consume(['event' => 'first']);
        $writer->consume(['event' => 'second']);

        $lines = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertCount(2, $lines);

        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            self::assertIsArray($decoded, 'Each line must be valid JSON');
        }
    }

    public function testAppendMode(): void
    {
        // Pre-populate the file
        file_put_contents($this->logFile, '{"event":"existing"}' . "\n");

        $writer = new AuditLogWriter($this->logFile, 'run-1', 'sha-1', '1.0.0');
        $writer->consume(['event' => 'new']);

        $lines = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertCount(2, $lines);
        self::assertStringContainsString('existing', $lines[0]);
        self::assertStringContainsString('new', $lines[1]);
    }
}
