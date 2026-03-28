<?php

declare(strict_types=1);

namespace App\Orchestrator;

/**
 * Plans a multi-hop upgrade sequence for any valid from→to version range.
 *
 * Wraps the single-hop logic of {@see HopPlanner} and generates the complete
 * ordered chain of intermediate hops required to cover the requested range.
 * For example, planning 8→13 yields five hops: 8→9, 9→10, 10→11, 11→12, 12→13.
 */
final class MultiHopPlanner
{
    /**
     * All supported consecutive hop image names, keyed as "from:to".
     *
     * @var array<string, string>
     */
    private readonly array $hopImages;

    /**
     * @param array<string, string> $hopImages Map of 'from:to' => docker image name.
     *                                          Defaults to all Phase 2 supported hops.
     */
    public function __construct(
        array $hopImages = [
            '8:9'   => 'upgrader/hop-8-to-9',
            '9:10'  => 'upgrader/hop-9-to-10',
            '10:11' => 'upgrader/hop-10-to-11',
            '11:12' => 'upgrader/hop-11-to-12',
            '12:13' => 'upgrader/hop-12-to-13',
        ],
    ) {
        $this->hopImages = $hopImages;
    }

    /**
     * Plans the full ordered hop sequence from $fromVersion to $toVersion.
     *
     * Each integer step between the two versions becomes a single hop. If any
     * intermediate consecutive hop is missing from the image map, an exception
     * is thrown before any container is executed.
     *
     * @throws InvalidHopException for empty/non-numeric inputs, downgrades, same
     *                             version, or a gap in the supported hop map
     */
    public function plan(string $fromVersion, string $toVersion): HopSequence
    {
        if ($fromVersion === '' || $toVersion === '') {
            throw new InvalidHopException('Version strings must not be empty.');
        }

        if (!ctype_digit($fromVersion) || !ctype_digit($toVersion)) {
            throw new InvalidHopException(sprintf(
                'Version strings must be numeric integers, got "%s" and "%s".',
                $fromVersion,
                $toVersion,
            ));
        }

        $from = (int) $fromVersion;
        $to   = (int) $toVersion;

        if ($from >= $to) {
            throw new InvalidHopException(sprintf(
                'Target version must be strictly greater than source version (got %s → %s).',
                $fromVersion,
                $toVersion,
            ));
        }

        $hops = [];

        for ($v = $from; $v < $to; $v++) {
            $key = sprintf('%d:%d', $v, $v + 1);

            if (!isset($this->hopImages[$key])) {
                throw new InvalidHopException(sprintf(
                    'No hop path defined for %d → %d. Supported hops: %s.',
                    $v,
                    $v + 1,
                    implode(', ', array_keys($this->hopImages)),
                ));
            }

            $hops[] = new Hop(
                dockerImage: $this->hopImages[$key],
                fromVersion: (string) $v,
                toVersion:   (string) ($v + 1),
                type:        'laravel',
                phpBase:     null,
            );
        }

        return new HopSequence($hops);
    }

    /**
     * Returns the raw hop-image map; useful for introspection in tests.
     *
     * @return array<string, string>
     */
    public function getHopImages(): array
    {
        return $this->hopImages;
    }
}
