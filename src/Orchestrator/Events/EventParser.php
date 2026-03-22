<?php

declare(strict_types=1);

namespace App\Orchestrator\Events;

final class EventParser
{
    /**
     * Parse one JSON-ND line from container stdout.
     * Returns a typed event VO, or a WarningEvent if the line is malformed/unknown.
     * Never throws.
     */
    public function parseLine(string $line): BaseEvent
    {
        $line = trim($line);

        if ($line === '') {
            return WarningEvent::fromMessage('Empty JSON-ND line');
        }

        try {
            $data = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return WarningEvent::fromMessage('Malformed JSON-ND line', ['raw' => $line, 'error' => $e->getMessage()]);
        }

        if (!is_array($data)) {
            return WarningEvent::fromMessage('Malformed JSON-ND line', ['raw' => $line]);
        }

        if (!isset($data['event']) || !is_string($data['event'])) {
            return WarningEvent::fromMessage('Missing event type', ['raw' => $line]);
        }

        $type = $data['event'];

        try {
            return match ($type) {
                EventCatalogue::PIPELINE_START          => PipelineStartEvent::fromArray($data),
                EventCatalogue::STAGE_START             => StageStartEvent::fromArray($data),
                EventCatalogue::STAGE_COMPLETE          => StageCompleteEvent::fromArray($data),
                EventCatalogue::FILE_CHANGED            => FileChangedEvent::fromArray($data),
                EventCatalogue::CHECKPOINT_WRITTEN      => CheckpointWrittenEvent::fromArray($data),
                EventCatalogue::BREAKING_CHANGE_APPLIED => BreakingChangeAppliedEvent::fromArray($data),
                EventCatalogue::MANUAL_REVIEW_REQUIRED  => ManualReviewRequiredEvent::fromArray($data),
                EventCatalogue::DEPENDENCY_BLOCKER      => DependencyBlockerEvent::fromArray($data),
                EventCatalogue::VERIFICATION_RESULT     => VerificationResultEvent::fromArray($data),
                EventCatalogue::PHPSTAN_REGRESSION      => PhpstanRegressionEvent::fromArray($data),
                EventCatalogue::HOP_COMPLETE            => HopCompleteEvent::fromArray($data),
                EventCatalogue::PIPELINE_ERROR          => PipelineErrorEvent::fromArray($data),
                EventCatalogue::WARNING                 => WarningEvent::fromArray($data),
                default                                 => WarningEvent::fromMessage(
                    'Unknown event type: ' . $type,
                    ['raw' => $line],
                ),
            };
        } catch (\Throwable $e) {
            return WarningEvent::fromMessage(
                'Failed to parse event: ' . $e->getMessage(),
                ['type' => $type, 'raw' => $line],
            );
        }
    }

    /**
     * Parse multiple lines (e.g. full audit log replay).
     *
     * @return list<BaseEvent>
     */
    public function parseLines(string $ndjson): array
    {
        $events = [];

        foreach (explode("\n", $ndjson) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $events[] = $this->parseLine($line);
        }

        return $events;
    }
}
