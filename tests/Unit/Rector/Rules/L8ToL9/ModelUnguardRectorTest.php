<?php

declare(strict_types=1);

namespace Tests\Unit\Rector\Rules\L8ToL9;

use AppContainer\Rector\Rules\L8ToL9\ModelUnguardRector;
use Iterator;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;

/**
 * @see ModelUnguardRector
 */
final class ModelUnguardRectorTest extends AbstractRectorTestCase
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
        return self::yieldFilesFromDirectory(__DIR__ . '/Fixture/ModelUnguard');
    }

    public function provideConfigFilePath(): string
    {
        return __DIR__ . '/config/model_unguard_rector.php';
    }
}
