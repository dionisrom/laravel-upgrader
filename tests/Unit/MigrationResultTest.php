<?php

declare(strict_types=1);

namespace Tests\Unit;

use AppContainer\Config\MigrationResult;
use PHPUnit\Framework\TestCase;

class MigrationResultTest extends TestCase
{
    public function testSuccessFactory(): void
    {
        $result = MigrationResult::success(['auth_guards_change', 'cache_redis_driver']);

        $this->assertTrue($result->success);
        $this->assertSame(['auth_guards_change', 'cache_redis_driver'], $result->appliedMigrations);
        $this->assertNull($result->errorMessage);
        $this->assertNull($result->rolledBackFrom);
    }

    public function testFailureFactory(): void
    {
        $result = MigrationResult::failure('Something broke', '/tmp/snapshot.tar');

        $this->assertFalse($result->success);
        $this->assertSame([], $result->appliedMigrations);
        $this->assertSame('Something broke', $result->errorMessage);
        $this->assertSame('/tmp/snapshot.tar', $result->rolledBackFrom);
    }
}
