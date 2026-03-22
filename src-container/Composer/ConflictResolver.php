<?php

declare(strict_types=1);

namespace AppContainer\Composer;

use AppContainer\Composer\Exception\DependencyBlockerException;

final class ConflictResolver
{
    /**
     * @param DependencyBlocker[] $blockers
     * @throws DependencyBlockerException when critical blockers exist and $ignoreBlockers is false
     */
    public function resolve(array $blockers, bool $ignoreBlockers = false): ResolutionResult
    {
        $critical = array_filter(
            $blockers,
            static fn(DependencyBlocker $b) => $b->severity === 'critical',
        );

        if (!$ignoreBlockers && count($critical) > 0) {
            throw new DependencyBlockerException(array_values($critical));
        }

        if ($ignoreBlockers) {
            return new ResolutionResult(
                applied: [],
                bypassed: array_values($blockers),
            );
        }

        // Only warnings remain — apply them (emit events, continue pipeline)
        return new ResolutionResult(
            applied: array_values($blockers),
            bypassed: [],
        );
    }
}
