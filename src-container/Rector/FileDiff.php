<?php

declare(strict_types=1);

namespace AppContainer\Rector;

final class FileDiff
{
    /**
     * @param string[] $appliedRectors
     */
    public function __construct(
        public readonly string $file,
        public readonly string $diff,
        public readonly array $appliedRectors,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $appliedRectors = $data['applied_rectors'] ?? [];

        if (!is_array($appliedRectors)) {
            $appliedRectors = [];
        }

        return new self(
            file: (string) ($data['file'] ?? ''),
            diff: (string) ($data['diff'] ?? ''),
            appliedRectors: array_map('strval', $appliedRectors),
        );
    }
}
