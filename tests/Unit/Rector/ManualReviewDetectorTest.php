<?php

declare(strict_types=1);

namespace Tests\Unit\Rector;

use AppContainer\Rector\ManualReviewDetector;
use PHPUnit\Framework\TestCase;

final class ManualReviewDetectorTest extends TestCase
{
    private string $tmpDir;
    private ManualReviewDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/manual-review-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        $this->detector = new ManualReviewDetector();
    }

    protected function tearDown(): void
    {
        $files = glob($this->tmpDir . '/*.php');
        foreach ($files as $file) {
            unlink($file);
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
        parent::tearDown();
    }

    public function test_detects_magic_methods(): void
    {
        file_put_contents($this->tmpDir . '/Magic.php', <<<'PHP'
<?php
class Magic {
    public function __call($name, $args) {}
    public function __get($name) {}
}
PHP);

        $items = $this->detectSilently();

        $patterns = array_column(
            array_map(fn ($i) => ['pattern' => $i->pattern, 'detail' => $i->detail], $items),
            'detail',
            'pattern',
        );

        self::assertArrayHasKey('magic_method', $patterns);
    }

    public function test_detects_macro_calls(): void
    {
        file_put_contents($this->tmpDir . '/MacroUsage.php', <<<'PHP'
<?php
use Illuminate\Support\Str;
Str::macro('customMethod', function () {});
PHP);

        $items = $this->detectSilently();

        self::assertNotEmpty($items);
        self::assertSame('macro', $items[0]->pattern);
    }

    public function test_detects_dynamic_instantiation(): void
    {
        file_put_contents($this->tmpDir . '/Dynamic.php', <<<'PHP'
<?php
$className = 'App\\Models\\User';
$obj = new $className();
PHP);

        $items = $this->detectSilently();

        self::assertNotEmpty($items);
        self::assertSame('dynamic_instantiation', $items[0]->pattern);
    }

    public function test_detects_dynamic_method_calls(): void
    {
        file_put_contents($this->tmpDir . '/DynCall.php', <<<'PHP'
<?php
$method = 'doSomething';
$obj->$method();
PHP);

        $items = $this->detectSilently();

        self::assertNotEmpty($items);
        self::assertSame('dynamic_call', $items[0]->pattern);
    }

    public function test_returns_empty_for_clean_file(): void
    {
        file_put_contents($this->tmpDir . '/Clean.php', <<<'PHP'
<?php
class Clean {
    public function handle(): void {}
}
PHP);

        $items = $this->detectSilently();

        self::assertEmpty($items);
    }

    /**
     * Run detection while suppressing stdout (JSON-ND events).
     * @return \AppContainer\Rector\ManualReviewItem[]
     */
    private function detectSilently(): array
    {
        ob_start();
        $items = $this->detector->detect($this->tmpDir);
        ob_end_clean();

        return $items;
    }
}
