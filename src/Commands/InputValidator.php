<?php

declare(strict_types=1);

namespace App\Commands;

final class InputValidator
{
    private const ALLOWED_FORMATS = ['html', 'json', 'md'];

    /**
     * Validate raw CLI options.
     *
     * @param array<string, mixed> $options  Raw options from InputInterface
     * @return list<string> List of error messages (empty = valid)
     */
    public function validate(array $options): array
    {
        $errors = [];

        // repo: required, non-empty
        $repo = isset($options['repo']) ? (string) $options['repo'] : '';
        if ($repo === '') {
            $errors[] = 'The --repo option is required.';
        } else {
            $errors = array_values(array_merge($errors, $this->validateRepo($repo)));
        }

        // to: if provided, must be a positive integer; Phase 1 → must equal 9
        if (isset($options['to']) && $options['to'] !== null) {
            $to = (string) $options['to'];
            if (!ctype_digit($to) || (int) $to <= 0) {
                $errors[] = sprintf('The --to option must be a positive integer, got "%s".', $to);
            } elseif ((int) $to !== 9) {
                $errors[] = sprintf(
                    'Phase 1 only supports upgrading to Laravel 9 (--to=9), got "%s".',
                    $to,
                );
            }
        }

        // from: if provided, must be a positive integer ≤ to
        if (isset($options['from']) && $options['from'] !== null) {
            $from = (string) $options['from'];
            $to   = isset($options['to']) ? (int) $options['to'] : 9;

            if (!ctype_digit($from) || (int) $from <= 0) {
                $errors[] = sprintf('The --from option must be a positive integer, got "%s".', $from);
            } elseif ((int) $from >= $to) {
                $errors[] = sprintf(
                    'The --from value (%s) must be less than --to value (%s).',
                    $from,
                    $to,
                );
            }
        }

        // format: if provided, must be comma-separated subset of allowed values
        if (isset($options['format']) && $options['format'] !== null && $options['format'] !== '') {
            $formats = array_map('trim', explode(',', (string) $options['format']));
            foreach ($formats as $fmt) {
                if (!in_array($fmt, self::ALLOWED_FORMATS, true)) {
                    $errors[] = sprintf(
                        'Invalid format "%s". Allowed: %s.',
                        $fmt,
                        implode(', ', self::ALLOWED_FORMATS),
                    );
                }
            }
        }

        return array_values($errors);
    }

    /**
     * @return list<string>
     */
    private function validateRepo(string $repo): array
    {
        $errors = [];

        $isRemote = str_starts_with($repo, 'github:')
            || str_starts_with($repo, 'gitlab:')
            || str_starts_with($repo, 'https://');

        if ($isRemote) {
            // Remote repos are valid as long as they're non-empty (already checked above)
            return $errors;
        }

        // Local path must exist as a directory
        if (!is_dir($repo)) {
            $errors[] = sprintf(
                'Local repository path "%s" does not exist or is not a directory.',
                $repo,
            );
        }

        return $errors;
    }
}
