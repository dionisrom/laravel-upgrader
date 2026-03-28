<?php

declare(strict_types=1);

namespace Tests\Unit;

use AppContainer\Config\SafeConfigParser;
use PHPUnit\Framework\TestCase;

class SafeConfigParserTest extends TestCase
{
    private string $tmpDir;
    private SafeConfigParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/safe_config_parser_test_' . uniqid();
        mkdir($this->tmpDir, 0700, true);
        $this->parser = new SafeConfigParser();
    }

    protected function tearDown(): void
    {
        $files = glob($this->tmpDir . '/*') ?: [];
        foreach ($files as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    private function writeConfig(string $content): string
    {
        $path = $this->tmpDir . '/test.php';
        file_put_contents($path, $content);
        return $path;
    }

    public function testParsesSimpleArray(): void
    {
        $path = $this->writeConfig("<?php\n\nreturn [\n    'key' => 'value',\n    'number' => 42,\n];\n");

        $result = $this->parser->parse($path);

        $this->assertSame('value', $result['key']);
        $this->assertSame(42, $result['number']);
    }

    public function testParsesNestedArrays(): void
    {
        $path = $this->writeConfig("<?php\n\nreturn [\n    'nested' => [\n        'inner' => true,\n    ],\n];\n");

        $result = $this->parser->parse($path);

        $this->assertTrue($result['nested']['inner']);
    }

    public function testParsesEnvCallsWithDefault(): void
    {
        $path = $this->writeConfig("<?php\n\nreturn [\n    'name' => env('APP_NAME', 'Laravel'),\n];\n");

        // Ensure env var is not set
        putenv('APP_NAME');

        $result = $this->parser->parse($path);

        $this->assertSame('Laravel', $result['name']);
    }

    public function testParsesNullValues(): void
    {
        $path = $this->writeConfig("<?php\n\nreturn [\n    'same_site' => null,\n];\n");

        $result = $this->parser->parse($path);

        $this->assertNull($result['same_site']);
    }

    public function testParsesBooleans(): void
    {
        $path = $this->writeConfig("<?php\n\nreturn [\n    'debug' => true,\n    'cache' => false,\n];\n");

        $result = $this->parser->parse($path);

        $this->assertTrue($result['debug']);
        $this->assertFalse($result['cache']);
    }

    public function testThrowsOnNonArrayReturn(): void
    {
        $path = $this->writeConfig("<?php\n\nreturn 'not an array';\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not return an array');

        $this->parser->parse($path);
    }

    public function testThrowsOnMissingReturn(): void
    {
        $path = $this->writeConfig("<?php\n\n\$x = 1;\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not contain a return statement');

        $this->parser->parse($path);
    }

    public function testDoesNotExecuteArbitraryCode(): void
    {
        // If include were used, this would create the sentinel file
        $sentinel = $this->tmpDir . '/executed.txt';
        $path = $this->writeConfig("<?php\nfile_put_contents('{$sentinel}', 'pwned');\nreturn ['key' => 'value'];\n");

        $result = $this->parser->parse($path);

        $this->assertSame('value', $result['key']);
        $this->assertFileDoesNotExist($sentinel, 'Config parser must NOT execute arbitrary code');
    }
}
