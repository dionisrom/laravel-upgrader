<?php

declare(strict_types=1);

namespace AppContainer\Composer\Exception;

use AppContainer\Composer\DependencyBlocker;
use RuntimeException;

final class DependencyBlockerException extends RuntimeException
{
    /** @var DependencyBlocker[] */
    private array $blockers;

    /**
     * @param DependencyBlocker[] $blockers
     */
    public function __construct(array $blockers, string $message = '')
    {
        if ($message === '') {
            $names = implode(', ', array_map(
                static fn(DependencyBlocker $b) => $b->package,
                $blockers,
            ));
            $message = "Critical dependency blockers prevent upgrade: {$names}";
        }

        parent::__construct($message);
        $this->blockers = $blockers;
    }

    /** @return DependencyBlocker[] */
    public function getBlockers(): array
    {
        return $this->blockers;
    }
}
