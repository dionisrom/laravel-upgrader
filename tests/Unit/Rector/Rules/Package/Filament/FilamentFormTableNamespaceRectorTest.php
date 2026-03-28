<?php

declare(strict_types=1);

namespace Tests\Unit\Rector\Rules\Package\Filament;

use AppContainer\Rector\Rules\Package\Filament\FilamentFormTableNamespaceRector;
use Iterator;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;

/**
 * @see FilamentFormTableNamespaceRector
 */
final class FilamentFormTableNamespaceRectorTest extends AbstractRectorTestCase
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
        return self::yieldFilesFromDirectory(__DIR__ . '/Fixture/FormTableNamespace');
    }

    public function provideConfigFilePath(): string
    {
        return __DIR__ . '/config/filament_form_table_namespace_rector.php';
    }
}
