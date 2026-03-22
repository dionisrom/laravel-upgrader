<?php

declare(strict_types=1);

namespace AppContainer\Rector;

final readonly class RectorError
{
    public function __construct(
        public readonly string $file,
        public readonly string $message,
        public readonly int $line,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            file: (string) ($data['file'] ?? ''),
            message: (string) ($data['message'] ?? ''),
            line: (int) ($data['line'] ?? 0),
        );
    }
}
