<?php

declare(strict_types=1);

namespace Tests\Unit\Report\Renderer;

use App\Report\Renderer\AnnotationRenderer;
use PHPUnit\Framework\TestCase;

final class AnnotationRendererTest extends TestCase
{
    private AnnotationRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer = new AnnotationRenderer();
    }

    public function testEmptyRulesReturnsEmptyString(): void
    {
        $this->assertSame('', $this->renderer->render([]));
    }

    public function testSingleRuleRendersBadge(): void
    {
        $html = $this->renderer->render(['SomeRule']);

        $this->assertStringContainsString('rule-badge', $html);
        $this->assertStringContainsString('SomeRule', $html);
        $this->assertStringContainsString('rule-annotations', $html);
    }

    public function testMultipleRulesRenderMultipleBadges(): void
    {
        $html = $this->renderer->render(['RuleA', 'RuleB', 'RuleC']);

        $this->assertSame(3, substr_count($html, 'rule-badge'));
        $this->assertStringContainsString('RuleA', $html);
        $this->assertStringContainsString('RuleB', $html);
        $this->assertStringContainsString('RuleC', $html);
    }

    public function testFqcnShortenedToClassName(): void
    {
        $fqcn = 'Rector\\Laravel\\Rector\\Class_\\AddParentRegisterMiddlewareCallRector';
        $html = $this->renderer->render([$fqcn]);

        // The display text should be the short class name
        $this->assertStringContainsString('AddParentRegisterMiddlewareCallRector', $html);
        // The full FQCN should appear in the title attribute
        $this->assertStringContainsString(htmlspecialchars($fqcn, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $html);
    }

    public function testHtmlSpecialCharsInRuleNameAreEscaped(): void
    {
        $malicious = '<script>alert("xss")</script>';
        $html = $this->renderer->render([$malicious]);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testWrappedInAnnotationsContainer(): void
    {
        $html = $this->renderer->render(['Rule1']);

        $this->assertStringContainsString('<div class="rule-annotations"', $html);
        $this->assertStringContainsString('</div>', $html);
    }

    public function testAriaLabelPresent(): void
    {
        $html = $this->renderer->render(['TestRule']);

        $this->assertStringContainsString('aria-label="Applied Rector rules"', $html);
        $this->assertStringContainsString('aria-label="Rector rule:', $html);
    }
}
