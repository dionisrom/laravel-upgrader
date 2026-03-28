<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Orchestrator\TerminalRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

final class TerminalRendererTest extends TestCase
{
    private BufferedOutput $output;
    private TerminalRenderer $renderer;

    protected function setUp(): void
    {
        $this->output = new BufferedOutput();
        $this->renderer = new TerminalRenderer($this->output);
    }

    public function testPipelineStartRendersRepoName(): void
    {
        $this->renderer->consume(['event' => 'pipeline_start', 'repo' => 'my/app']);

        self::assertStringContainsString('my/app', $this->output->fetch());
    }

    public function testPipelineStartFallsBackToConfiguredRepoLabel(): void
    {
        $renderer = new TerminalRenderer($this->output, 'configured/repo');

        $renderer->consume(['event' => 'pipeline_start']);

        self::assertStringContainsString('configured/repo', $this->output->fetch());
    }

    public function testPipelineCompletePassedRendersSuccess(): void
    {
        $this->renderer->consume(['event' => 'pipeline_complete', 'passed' => true]);

        $out = $this->output->fetch();
        self::assertStringContainsString('successfully', $out);
    }

    public function testPipelineCompleteFailedRendersFailure(): void
    {
        $this->renderer->consume(['event' => 'pipeline_complete', 'passed' => false]);

        $out = $this->output->fetch();
        self::assertStringContainsString('failures', $out);
    }

    public function testStageStartRendersStageName(): void
    {
        $this->renderer->consume(['event' => 'stage_start', 'stage' => 'rector']);

        self::assertStringContainsString('rector', $this->output->fetch());
    }

    public function testStageCompleteRendersStageName(): void
    {
        $this->renderer->consume(['event' => 'stage_complete', 'stage' => 'rector']);

        self::assertStringContainsString('rector', $this->output->fetch());
    }

    public function testStageErrorRendersMessage(): void
    {
        $this->renderer->consume(['event' => 'stage_error', 'stage' => 'rector', 'message' => 'Parse error']);

        $out = $this->output->fetch();
        self::assertStringContainsString('rector', $out);
        self::assertStringContainsString('Parse error', $out);
    }

    public function testWarningRendersMessage(): void
    {
        $this->renderer->consume(['event' => 'warning', 'message' => 'Non-JSON line']);

        self::assertStringContainsString('Non-JSON line', $this->output->fetch());
    }

    public function testStderrRendersEachLine(): void
    {
        $this->renderer->consume(['event' => 'stderr', 'lines' => ['error 1', 'error 2']]);

        $out = $this->output->fetch();
        self::assertStringContainsString('error 1', $out);
        self::assertStringContainsString('error 2', $out);
    }

    public function testHopSkippedRendersHopKey(): void
    {
        $this->renderer->consume(['event' => 'hop_skipped', 'hop' => '8->9']);

        self::assertStringContainsString('8->9', $this->output->fetch());
    }

    public function testUnknownEventRendersJsonFallback(): void
    {
        $this->renderer->consume(['event' => 'custom_thing', 'data' => 42]);

        $out = $this->output->fetch();
        self::assertStringContainsString('custom_thing', $out);
    }
}
