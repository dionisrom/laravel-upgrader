<?php

declare(strict_types=1);

namespace AppContainer\Rector;

final readonly class RectorResult
{
    /**
     * @param FileDiff[]    $fileDiffs
     * @param RectorError[] $errors
     */
    public function __construct(
        public readonly array $fileDiffs,
        public readonly array $errors,
    ) {
    }

    public static function fromJson(string $json): self
    {
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw RectorExecutionException::fromInvalidJson($json, $e);
        }

        $rawDiffs = $decoded['file_diffs'] ?? [];
        $rawErrors = $decoded['errors'] ?? [];

        if (!is_array($rawDiffs)) {
            $rawDiffs = [];
        }

        if (!is_array($rawErrors)) {
            $rawErrors = [];
        }

        $fileDiffs = array_map(
            static fn (mixed $item): FileDiff => FileDiff::fromArray(
                is_array($item) ? $item : [],
            ),
            $rawDiffs,
        );

        $errors = array_map(
            static fn (mixed $item): RectorError => RectorError::fromArray(
                is_array($item) ? $item : [],
            ),
            $rawErrors,
        );

        return new self(
            fileDiffs: $fileDiffs,
            errors: $errors,
        );
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function changedFileCount(): int
    {
        return count($this->fileDiffs);
    }
}
