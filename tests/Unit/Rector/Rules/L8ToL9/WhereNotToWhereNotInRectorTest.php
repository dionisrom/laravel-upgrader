<?php

declare(strict_types=1);

namespace Tests\Unit\Rector\Rules\L8ToL9;

use AppContainer\Rector\Rules\L8ToL9\WhereNotToWhereNotInRector;
use Iterator;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;

/**
 * @see WhereNotToWhereNotInRector
 */
final class WhereNotToWhereNotInRectorTest extends AbstractRectorTestCase
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
        return self::yieldFilesFromDirectory(__DIR__ . '/Fixture/WhereNotToWhereNotIn');
    }

    public function provideConfigFilePath(): string
    {
        return __DIR__ . '/config/where_not_to_where_not_in_rector.php';
    }
}
