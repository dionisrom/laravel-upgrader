<?php

declare(strict_types=1);

namespace AppContainer;

use AppContainer\Exception\RegistryCorruptException;

class BreakingChangeRegistry
{
    /** @var array<string, mixed> */
    private array $data;

    /** @param array<string, mixed> $data */
    private function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * Load and validate a breaking-changes.json file.
     *
     * @throws RegistryCorruptException if JSON is invalid or fails schema validation
     */
    public static function load(string $jsonPath): self
    {
        if (!file_exists($jsonPath)) {
            throw new RegistryCorruptException("Registry file not found: {$jsonPath}");
        }

        $raw = file_get_contents($jsonPath);

        if ($raw === false) {
            throw new RegistryCorruptException("Could not read registry file: {$jsonPath}");
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RegistryCorruptException(
                "Invalid JSON in registry file: " . json_last_error_msg()
            );
        }

        if (!is_array($decoded)) {
            throw new RegistryCorruptException("Registry root must be a JSON object.");
        }

        $instance = new self($decoded);
        $instance->validate($decoded);

        return $instance;
    }

    /**
     * Return all breaking change entries.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        /** @var array<int, array<string, mixed>> */
        return $this->data['breaking_changes'];
    }

    /**
     * Filter entries by category.
     *
     * @return array<int, array<string, mixed>>
     */
    public function byCategory(string $category): array
    {
        return array_values(
            array_filter(
                $this->all(),
                static fn(array $entry): bool => $entry['category'] === $category
            )
        );
    }

    /**
     * Filter entries by severity.
     *
     * @return array<int, array<string, mixed>>
     */
    public function bySeverity(string $severity): array
    {
        return array_values(
            array_filter(
                $this->all(),
                static fn(array $entry): bool => $entry['severity'] === $severity
            )
        );
    }

    /**
     * Return entries that are fully automated (automated: true).
     *
     * @return array<int, array<string, mixed>>
     */
    public function automated(): array
    {
        return array_values(
            array_filter(
                $this->all(),
                static fn(array $entry): bool => $entry['automated'] === true
            )
        );
    }

    /**
     * Return entries that require manual review (manual_review_required: true).
     *
     * @return array<int, array<string, mixed>>
     */
    public function manualReview(): array
    {
        return array_values(
            array_filter(
                $this->all(),
                static fn(array $entry): bool => $entry['manual_review_required'] === true
            )
        );
    }

    /**
     * Validate the decoded JSON data against the registry schema.
     *
     * @param array<string, mixed> $data
     * @throws RegistryCorruptException
     */
    private function validate(array $data): void
    {
        $requiredTopLevel = ['hop', 'laravel_from', 'laravel_to', 'breaking_changes'];

        foreach ($requiredTopLevel as $key) {
            if (!array_key_exists($key, $data)) {
                throw new RegistryCorruptException("Missing required top-level key: '{$key}'");
            }
        }

        if (!is_array($data['breaking_changes'])) {
            throw new RegistryCorruptException("'breaking_changes' must be a JSON array.");
        }

        $validSeverities = ['blocker', 'high', 'medium', 'low'];
        $validCategories = ['eloquent', 'routing', 'middleware', 'config', 'helpers', 'environment', 'package', 'lumen'];
        $requiredEntryKeys = ['id', 'severity', 'category', 'title', 'automated'];
        $seenIds = [];

        foreach ($data['breaking_changes'] as $index => $entry) {
            if (!is_array($entry)) {
                throw new RegistryCorruptException(
                    "breaking_changes[{$index}] must be a JSON object."
                );
            }

            foreach ($requiredEntryKeys as $key) {
                if (!array_key_exists($key, $entry)) {
                    throw new RegistryCorruptException(
                        "breaking_changes[{$index}] missing required field: '{$key}'"
                    );
                }
            }

            $id = $entry['id'];

            if (!is_string($id) || $id === '') {
                throw new RegistryCorruptException(
                    "breaking_changes[{$index}] 'id' must be a non-empty string."
                );
            }

            if (isset($seenIds[$id])) {
                throw new RegistryCorruptException(
                    "Duplicate breaking change ID: '{$id}'"
                );
            }

            $seenIds[$id] = true;

            if (!in_array($entry['severity'], $validSeverities, true)) {
                throw new RegistryCorruptException(
                    "breaking_changes[{$index}] invalid severity '{$entry['severity']}'. "
                    . "Must be one of: " . implode(', ', $validSeverities)
                );
            }

            if (!in_array($entry['category'], $validCategories, true)) {
                throw new RegistryCorruptException(
                    "breaking_changes[{$index}] invalid category '{$entry['category']}'. "
                    . "Must be one of: " . implode(', ', $validCategories)
                );
            }
        }
    }
}
