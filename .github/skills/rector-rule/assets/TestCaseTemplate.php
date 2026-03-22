<?php

declare(strict_types=1);

namespace Tests\Unit\Rector\Rules\L8ToL9;

use App\Rector\Rules\L8ToL9\ExampleRector;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;

final class ExampleRectorTest extends AbstractRectorTestCase
{
    /**
     * @dataProvider provideData
     */
    public function test(string $filePath): void
    {
        $this->doTestFile($filePath);
    }

    /**
     * @return \Iterator<array{string}>
     */
    public static function provideData(): \Iterator
    {
        // Yields every .php.inc file in the Fixture/ directory
        return self::yieldFilesFromDirectory(__DIR__ . '/Fixture');
    }

    public function provideConfigFilePath(): string
    {
        return __DIR__ . '/config/configured_rule.php';
    }
}
