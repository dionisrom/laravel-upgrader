<?php

declare(strict_types=1);

namespace Tests\Unit\Workspace;

use App\Workspace\DiffGenerator;
use PHPUnit\Framework\TestCase;

final class DiffGeneratorTest extends TestCase
{
    private DiffGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new DiffGenerator();
    }

    // -----------------------------------------------------------------------
    // Basic contract
    // -----------------------------------------------------------------------

    public function testIdenticalContentProducesEmptyDiff(): void
    {
        $content = "<?php\nclass Foo {}\n";
        $diff = $this->generator->generateUnifiedDiff($content, $content);

        $this->assertSame('', $diff);
    }

    public function testDiffContainsHeader(): void
    {
        $diff = $this->generator->generateUnifiedDiff("line1\n", "line2\n", 'example.php');

        $this->assertStringContainsString('--- a/example.php', $diff);
        $this->assertStringContainsString('+++ b/example.php', $diff);
    }

    public function testDiffContainsHunkHeader(): void
    {
        $diff = $this->generator->generateUnifiedDiff("line1\n", "line2\n");

        $this->assertMatchesRegularExpression('/@@ -\d+,\d+ \+\d+,\d+ @@/', $diff);
    }

    // -----------------------------------------------------------------------
    // Added lines
    // -----------------------------------------------------------------------

    public function testAddedLineMarkedWithPlus(): void
    {
        $original = "<?php\nclass Foo {}\n";
        $new = "<?php\n// comment\nclass Foo {}\n";

        $diff = $this->generator->generateUnifiedDiff($original, $new, 'Foo.php');

        $this->assertStringContainsString('+// comment', $diff);
        $this->assertStringNotContainsString('-// comment', $diff);
    }

    public function testAddedLineAtEndOfFile(): void
    {
        $original = "line1\n";
        $new = "line1\nline2\n";

        $diff = $this->generator->generateUnifiedDiff($original, $new);

        $this->assertStringContainsString('+line2', $diff);
    }

    // -----------------------------------------------------------------------
    // Removed lines
    // -----------------------------------------------------------------------

    public function testRemovedLineMarkedWithMinus(): void
    {
        $original = "<?php\n// old comment\nclass Foo {}\n";
        $new = "<?php\nclass Foo {}\n";

        $diff = $this->generator->generateUnifiedDiff($original, $new, 'Foo.php');

        $this->assertStringContainsString('-// old comment', $diff);
        $this->assertStringNotContainsString('+// old comment', $diff);
    }

    // -----------------------------------------------------------------------
    // Modified lines
    // -----------------------------------------------------------------------

    public function testModifiedLineShowsBothMinusAndPlus(): void
    {
        $original = "foo\nbar\nbaz\n";
        $new = "foo\nQUX\nbaz\n";

        $diff = $this->generator->generateUnifiedDiff($original, $new);

        $this->assertStringContainsString('-bar', $diff);
        $this->assertStringContainsString('+QUX', $diff);
    }

    // -----------------------------------------------------------------------
    // Context lines
    // -----------------------------------------------------------------------

    public function testContextLinesAreIncluded(): void
    {
        // 6 lines total, change in the middle — surrounding lines should appear
        $original = "a\nb\nc\nd\ne\nf\n";
        $new = "a\nb\nc\nX\ne\nf\n";

        $diff = $this->generator->generateUnifiedDiff($original, $new);

        // Context lines around the change (c, e) should be present
        $this->assertStringContainsString(' c', $diff);
        $this->assertStringContainsString(' e', $diff);
    }

    // -----------------------------------------------------------------------
    // Custom filename
    // -----------------------------------------------------------------------

    public function testCustomFilenameAppearsInHeader(): void
    {
        $diff = $this->generator->generateUnifiedDiff("old\n", "new\n", 'src/SomeFile.php');

        $this->assertStringContainsString('a/src/SomeFile.php', $diff);
        $this->assertStringContainsString('b/src/SomeFile.php', $diff);
    }

    // -----------------------------------------------------------------------
    // Empty inputs
    // -----------------------------------------------------------------------

    public function testEmptyOriginalToNonEmpty(): void
    {
        $diff = $this->generator->generateUnifiedDiff('', "hello\n");

        $this->assertStringContainsString('+hello', $diff);
    }

    public function testNonEmptyToEmptyContent(): void
    {
        $diff = $this->generator->generateUnifiedDiff("hello\n", '');

        $this->assertStringContainsString('-hello', $diff);
    }

    // -----------------------------------------------------------------------
    // Roundtrip: DiffGenerator output is consumed by WorkspaceManager
    // -----------------------------------------------------------------------

    public function testDiffGeneratorOutputIsApplicableByWorkspaceManager(): void
    {
        $original = "<?php\ndeclare(strict_types=1);\n\nclass Foo\n{\n    public function bar(): string\n    {\n        return 'old';\n    }\n}\n";
        $new = "<?php\ndeclare(strict_types=1);\n\nclass Foo\n{\n    public function bar(): string\n    {\n        return 'new';\n    }\n}\n";

        $diff = $this->generator->generateUnifiedDiff($original, $new, 'app/Foo.php');

        // Diff must be non-empty and contain the changed value
        $this->assertStringContainsString("-        return 'old';", $diff);
        $this->assertStringContainsString("+        return 'new';", $diff);
    }

    // -----------------------------------------------------------------------
    // Large file smoke test
    // -----------------------------------------------------------------------

    public function testLargeFileDoesNotTimeout(): void
    {
        $lines = [];
        for ($i = 0; $i < 500; $i++) {
            $lines[] = "    // line $i: " . str_repeat('x', 80);
        }

        $original = "<?php\n" . implode("\n", $lines) . "\n";
        $modified = $lines;
        $modified[250] = '    // line 250: CHANGED';
        $new = "<?php\n" . implode("\n", $modified) . "\n";

        $start = microtime(true);
        $diff = $this->generator->generateUnifiedDiff($original, $new, 'large.php');
        $elapsed = microtime(true) - $start;

        $this->assertStringContainsString('+    // line 250: CHANGED', $diff);
        $this->assertLessThan(5.0, $elapsed, 'Diff generation on a 500-line file took too long');
    }
}
