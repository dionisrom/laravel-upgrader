<?php

declare(strict_types=1);

namespace AppContainer\Composer;

final readonly class PackageCompatibility
{
    /**
     * @param bool|string $l9Support  true | false | "unknown"
     */
    public function __construct(
        public readonly string $package,
        public readonly bool|string $l9Support,
        public readonly string|null $recommendedVersion,
        public readonly string $notes,
    ) {}

    /** l9_support === false */
    public function isBlocker(): bool
    {
        return $this->l9Support === false;
    }

    /** l9_support === "unknown" */
    public function isUnknown(): bool
    {
        return $this->l9Support === 'unknown';
    }
}
