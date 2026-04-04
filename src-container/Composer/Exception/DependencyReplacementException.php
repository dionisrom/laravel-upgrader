<?php

declare(strict_types=1);

namespace AppContainer\Composer\Exception;

use AppContainer\Composer\DependencyReplacement;

/**
 * Exception thrown when package replacements lack corresponding code updates.
 */
final class DependencyReplacementException extends \RuntimeException
{
    /**
     * @param list<DependencyReplacement> $unhandledReplacements
     */
    public function __construct(
        string $message,
        private readonly array $unhandledReplacements = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @return list<DependencyReplacement>
     */
    public function getUnhandledReplacements(): array
    {
        return $this->unhandledReplacements;
    }

    /**
     * @param list<DependencyReplacement> $replacements
     */
    public static function fromUnhandledReplacements(array $replacements): self
    {
        $packageNames = array_map(
            static fn(DependencyReplacement $r): string => $r->oldPackage,
            $replacements,
        );

        return new self(
            sprintf(
                'The following package replacements require manual code updates (no Rector rules available): %s',
                implode(', ', $packageNames),
            ),
            $replacements,
        );
    }
}
