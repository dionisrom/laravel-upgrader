<?php

declare(strict_types=1);

namespace App\Orchestrator;

final class HopPlanner
{
    /**
     * Supported hop paths: 'fromVersion:toVersion' => docker image name.
     *
     * @var array<string, string>
     */
    private readonly array $hopImages;

    /**
     * @param array<string, string> $hopImages Map of 'from:to' => docker image name.
     *                                          Defaults to the Phase 1 supported hop.
     */
    public function __construct(
        array $hopImages = ['8:9' => 'upgrader/hop-8-to-9'],
    ) {
        $this->hopImages = $hopImages;
    }

    /**
     * Builds an ordered HopSequence for the given version range.
     *
     * Phase 1 limitation: only '8' → '9' is supported.
     *
     * @throws InvalidHopException for empty/non-numeric inputs, downgrades, or unsupported pairs
     */
    public function plan(string $fromVersion, string $toVersion): HopSequence
    {
        if ($fromVersion === '' || $toVersion === '') {
            throw new InvalidHopException(
                'Version strings must not be empty.',
            );
        }

        if (!ctype_digit($fromVersion) || !ctype_digit($toVersion)) {
            throw new InvalidHopException(sprintf(
                'Version strings must be numeric integers, got "%s" and "%s".',
                $fromVersion,
                $toVersion,
            ));
        }

        if ((int) $fromVersion >= (int) $toVersion) {
            throw new InvalidHopException(sprintf(
                'Target version must be strictly greater than source version (got %s → %s).',
                $fromVersion,
                $toVersion,
            ));
        }

        $key = sprintf('%s:%s', $fromVersion, $toVersion);

        if (!isset($this->hopImages[$key])) {
            throw new InvalidHopException(sprintf(
                'No hop path is defined for %s → %s. Supported paths: %s.',
                $fromVersion,
                $toVersion,
                implode(', ', array_keys($this->hopImages)),
            ));
        }

        $hop = new Hop(
            dockerImage: $this->hopImages[$key],
            fromVersion: $fromVersion,
            toVersion: $toVersion,
            type: 'laravel',
            phpBase: null,
        );

        return new HopSequence([$hop]);
    }
}
