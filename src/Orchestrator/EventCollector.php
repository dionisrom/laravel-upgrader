<?php

declare(strict_types=1);

namespace App\Orchestrator;

/**
 * Collects all dispatched events and tracks verification status.
 * Used by UpgradeOrchestrator to detect pipeline_complete with passed=true.
 */
final class EventCollector implements EventConsumerInterface
{
    /** @var list<array<string, mixed>> */
    private array $events = [];

    private bool $verificationPassed = false;

    /**
     * @param array<string, mixed> $event
     */
    public function consume(array $event): void
    {
        $this->events[] = $event;

        if (
            ($event['event'] ?? '') === 'pipeline_complete'
            && ($event['passed'] ?? false) === true
        ) {
            $this->verificationPassed = true;
        }
    }

    public function isVerificationPassed(): bool
    {
        return $this->verificationPassed;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getEvents(): array
    {
        return $this->events;
    }

    public function reset(): void
    {
        $this->events = [];
        $this->verificationPassed = false;
    }
}
