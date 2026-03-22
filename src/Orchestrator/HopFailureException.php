<?php

declare(strict_types=1);

namespace App\Orchestrator;

final class HopFailureException extends \RuntimeException
{
    /**
     * @param list<string> $lastStderrLines
     */
    public function __construct(
        private readonly int $exitCode,
        private readonly array $lastStderrLines,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message !== '' ? $message : sprintf('Hop failed with exit code %d', $exitCode),
            0,
            $previous,
        );
    }

    public function getExitCode(): int
    {
        return $this->exitCode;
    }

    /**
     * @return list<string>
     */
    public function getLastStderrLines(): array
    {
        return $this->lastStderrLines;
    }
}
