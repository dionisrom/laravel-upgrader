<?php

declare(strict_types=1);

namespace Tests\Unit\Rector\Rules\Package\Livewire;

use AppContainer\Rector\Rules\Package\Livewire\EmitToDispatchRector;
use Iterator;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;

/**
 * @see EmitToDispatchRector
 */
final class EmitToDispatchRectorTest extends AbstractRectorTestCase
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
        return self::yieldFilesFromDirectory(__DIR__ . '/Fixture/EmitToDispatch');
    }

    public function provideConfigFilePath(): string
    {
        return __DIR__ . '/config/emit_to_dispatch_rector.php';
    }
}
