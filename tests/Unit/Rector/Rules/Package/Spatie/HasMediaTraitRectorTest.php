<?php

declare(strict_types=1);

namespace Tests\Unit\Rector\Rules\Package\Spatie;

use AppContainer\Rector\Rules\Package\Spatie\HasMediaTraitRector;
use Iterator;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;

/**
 * @see HasMediaTraitRector
 */
final class HasMediaTraitRectorTest extends AbstractRectorTestCase
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
        return self::yieldFilesFromDirectory(__DIR__ . '/Fixture/HasMediaTrait');
    }

    public function provideConfigFilePath(): string
    {
        return __DIR__ . '/config/has_media_trait_rector.php';
    }
}
