<?php

declare(strict_types=1);

namespace Tests\Unit\Rector\Rules\L12ToL13;

use AppContainer\Rector\Rules\L12ToL13\DeprecatedApiRemoverRector;
use Iterator;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;

/**
 * @see DeprecatedApiRemoverRector
 */
final class DeprecatedApiRemoverRectorTest extends AbstractRectorTestCase
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
        return self::yieldFilesFromDirectory(__DIR__ . '/Fixture/DeprecatedApiRemover');
    }

    public function provideConfigFilePath(): string
    {
        return __DIR__ . '/config/deprecated_api_remover_rector.php';
    }
}
