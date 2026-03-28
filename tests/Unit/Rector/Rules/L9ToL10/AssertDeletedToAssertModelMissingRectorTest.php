<?php

declare(strict_types=1);

namespace Tests\Unit\Rector\Rules\L9ToL10;

use AppContainer\Rector\Rules\L9ToL10\AssertDeletedToAssertModelMissingRector;
use Iterator;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;

/**
 * @see AssertDeletedToAssertModelMissingRector
 */
final class AssertDeletedToAssertModelMissingRectorTest extends AbstractRectorTestCase
{
    /**
     * @dataProvider provideData
     */
    public function test(string $filePath): void
    {
        $this->doTestFile($filePath);
    }

    /**
     * @return Iterator<array{string}>
     */
    public static function provideData(): Iterator
    {
        return self::yieldFilesFromDirectory(__DIR__ . '/Fixture/AssertDeleted');
    }

    public function provideConfigFilePath(): string
    {
        return __DIR__ . '/config/assert_deleted_rector.php';
    }
}
