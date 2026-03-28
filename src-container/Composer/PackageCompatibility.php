<?php

declare(strict_types=1);

namespace AppContainer\Composer;

final class PackageCompatibility
{
    /**
     * @param bool|string $support  true | false | "unknown"
     */
    public function __construct(
        public readonly string $package,
        public readonly bool|string $support,
        public readonly string|null $recommendedVersion,
        public readonly string $notes,
    ) {}

    /** support === false */
    public function isBlocker(): bool
    {
        return $this->support === false;
    }

    /** support === "unknown" */
    public function isUnknown(): bool
    {
        return $this->support === 'unknown';
    }
}
