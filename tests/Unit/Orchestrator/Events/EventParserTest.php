<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator\Events;

use App\Orchestrator\Events\EventCatalogue;
use App\Orchestrator\Events\EventParser;
use App\Orchestrator\Events\FileChangedEvent;
use App\Orchestrator\Events\HopCompleteEvent;
use App\Orchestrator\Events\PipelineStartEvent;
use App\Orchestrator\Events\StageStartEvent;
use App\Orchestrator\Events\WarningEvent;
use PHPUnit\Framework\TestCase;

final class EventParserTest extends TestCase
{
    private EventParser $parser;

    protected function setUp(): void
    {
        $this->parser = new EventParser();
    }

    public function testParsePipelineStartEvent(): void
    {
        $line = json_encode([
            'event'        => EventCatalogue::PIPELINE_START,
            'hop'          => '8_to_9',
            'ts'           => 1_700_000_000.0,
            'seq'          => 1,
            'total_files'  => 120,
            'php_files'    => 100,
            'config_files' => 20,
        ]);
        self::assertIsString($line);

        $event = $this->parser->parseLine($line);

        self::assertInstanceOf(PipelineStartEvent::class, $event);
        self::assertSame(EventCatalogue::PIPELINE_START, $event->event);
        self::assertSame('8_to_9', $event->hop);
        self::assertSame(1, $event->seq);
        self::assertSame(120, $event->totalFiles);
        self::assertSame(100, $event->phpFiles);
        self::assertSame(20, $event->configFiles);
    }

    public function testParseStageStartEvent(): void
    {
        $line = json_encode([
            'event' => EventCatalogue::STAGE_START,
            'hop'   => '8_to_9',
            'ts'    => 1_700_000_001.0,
            'seq'   => 2,
            'stage' => 'rector',
        ]);
        self::assertIsString($line);

        $event = $this->parser->parseLine($line);

        self::assertInstanceOf(StageStartEvent::class, $event);
        self::assertSame('rector', $event->stage);
    }

    public function testParseFileChangedEvent(): void
    {
        $line = json_encode([
            'event'         => EventCatalogue::FILE_CHANGED,
            'hop'           => '8_to_9',
            'ts'            => 1_700_000_002.0,
            'seq'           => 3,
            'file'          => 'app/Http/Controllers/HomeController.php',
            'rules'         => ['RuleA', 'RuleB'],
            'lines_added'   => 5,
            'lines_removed' => 3,
        ]);
        self::assertIsString($line);

        $event = $this->parser->parseLine($line);

        self::assertInstanceOf(FileChangedEvent::class, $event);
        self::assertSame('app/Http/Controllers/HomeController.php', $event->file);
        self::assertSame(['RuleA', 'RuleB'], $event->rules);
        self::assertSame(5, $event->linesAdded);
        self::assertSame(3, $event->linesRemoved);
    }

    public function testParseHopCompleteEvent(): void
    {
        $line = json_encode([
            'event'               => EventCatalogue::HOP_COMPLETE,
            'hop'                 => '8_to_9',
            'ts'                  => 1_700_000_010.0,
            'seq'                 => 10,
            'confidence'          => 0.95,
            'manual_review_count' => 2,
            'files_changed'       => 42,
        ]);
        self::assertIsString($line);

        $event = $this->parser->parseLine($line);

        self::assertInstanceOf(HopCompleteEvent::class, $event);
        self::assertSame(0.95, $event->confidence);
        self::assertSame(2, $event->manualReviewCount);
        self::assertSame(42, $event->filesChanged);
    }

    public function testMalformedJsonReturnsWarningEvent(): void
    {
        $event = $this->parser->parseLine('not json {{{');

        self::assertInstanceOf(WarningEvent::class, $event);
        self::assertSame('Malformed JSON-ND line', $event->message);
    }

    public function testMissingEventTypeReturnsWarningEvent(): void
    {
        $line = json_encode(['foo' => 'bar']);
        self::assertIsString($line);

        $event = $this->parser->parseLine($line);

        self::assertInstanceOf(WarningEvent::class, $event);
        self::assertSame('Missing event type', $event->message);
    }

    public function testUnknownEventTypeReturnsWarningEvent(): void
    {
        $line = json_encode([
            'event' => 'foobar',
            'hop'   => '8_to_9',
            'ts'    => 1.0,
            'seq'   => 1,
        ]);
        self::assertIsString($line);

        $event = $this->parser->parseLine($line);

        self::assertInstanceOf(WarningEvent::class, $event);
        self::assertStringContainsString('foobar', $event->message);
    }

    public function testParseLines(): void
    {
        $ndjson = implode("\n", [
            json_encode(['event' => EventCatalogue::PIPELINE_START, 'hop' => '8_to_9', 'ts' => 1.0, 'seq' => 1, 'total_files' => 10, 'php_files' => 8, 'config_files' => 2]),
            json_encode(['event' => EventCatalogue::STAGE_START, 'hop' => '8_to_9', 'ts' => 2.0, 'seq' => 2, 'stage' => 'rector']),
            'bad json',
        ]);

        $events = $this->parser->parseLines($ndjson);

        self::assertCount(3, $events);
        self::assertInstanceOf(PipelineStartEvent::class, $events[0]);
        self::assertInstanceOf(StageStartEvent::class, $events[1]);
        self::assertInstanceOf(WarningEvent::class, $events[2]);
    }
}
