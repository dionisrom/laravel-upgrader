<?php

declare(strict_types=1);

namespace AppContainer\Composer\Exception;

use AppContainer\Composer\DependencyReplacement;

/**
 * Exception thrown when code updates are required but not completed.
 */
final class CodeUpdateRequiredException extends \RuntimeException
{
    /**
     * @param list<DependencyReplacement> $unhandledReplacements
     * @param list<array<string, mixed>> $codeUpdateReports
     */
    public function __construct(
        string $message,
        private readonly array $unhandledReplacements = [],
        private readonly array $codeUpdateReports = [],
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
     * @return list<array<string, mixed>>
     */
    public function getCodeUpdateReports(): array
    {
        return $this->codeUpdateReports;
    }

    /**
     * @param list<DependencyReplacement> $replacements
     * @param list<array<string, mixed>> $reports
     */
    public static function fromUnhandledReplacements(
        array $replacements,
        array $reports = [],
    ): self {
        $packageNames = array_map(
            static fn(DependencyReplacement $r): string => $r->oldPackage,
            $replacements,
        );

        $message = sprintf(
            "Code updates required for the following package replacements:\n" .
            "%s\n\n" .
            "These packages have been replaced but the code using them has not been updated.\n" .
            "Either:\n" .
            "1. Run the available Rector rules to update the code automatically\n" .
            "2. Manually update the code and mark as approved\n" .
            "3. Skip this validation with --ignore-code-updates (not recommended)",
            implode(', ', $packageNames),
        );

        return new self($message, $replacements, $reports);
    }
}
