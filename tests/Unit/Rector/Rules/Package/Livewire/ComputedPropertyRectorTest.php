<?php

declare(strict_types=1);

namespace Tests\Unit\Rector\Rules\Package\Livewire;

use AppContainer\Rector\Rules\Package\Livewire\ComputedPropertyRector;
use Iterator;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;

/**
 * @see ComputedPropertyRector
 */
final class ComputedPropertyRectorTest extends AbstractRectorTestCase
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
        return self::yieldFilesFromDirectory(__DIR__ . '/Fixture/ComputedProperty');
    }

    public function provideConfigFilePath(): string
    {
        return __DIR__ . '/config/computed_property_rector.php';
    }
}
