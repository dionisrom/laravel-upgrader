<?php

declare(strict_types=1);

namespace AppContainer\Config;

/**
 * Deep-merges PHP config arrays and renders them back to PHP source.
 *
 * Merge contract:
 *  - Keys in $existing NOT in $changes  → always preserved verbatim
 *  - Keys in $changes  NOT in $existing → always added
 *  - Both sides have the key, both are arrays → recurse (custom keys inside preserved)
 *  - Both sides have the key, scalar in $changes → only overwrite when key is in $knownChangedKeys
 */
final class ConfigMerger
{
    /**
     * Deep-merge $changes into $existing, overwriting scalars only for known-changed keys.
     *
     * @param  array<string, mixed> $existing
     * @param  array<string, mixed> $changes
     * @param  string[]             $knownChangedKeys  root-level keys safe to overwrite
     * @return array<string, mixed>
     */
    public function merge(array $existing, array $changes, array $knownChangedKeys): array
    {
        $result = $existing;

        foreach ($changes as $key => $value) {
            if (!array_key_exists($key, $existing)) {
                // New key — always add
                $result[$key] = $value;
                continue;
            }

            if (is_array($value) && is_array($existing[$key])) {
                // Both arrays → recurse; propagate nested known-changed keys
                /** @var array<string, mixed> $existingNested */
                $existingNested = $existing[$key];
                /** @var array<string, mixed> $changesNested */
                $changesNested = $value;

                // Collect sub-keys: "passwords.users" → "users" when current key is "passwords"
                $nestedKnown = [];
                $prefix = $key . '.';
                foreach ($knownChangedKeys as $kck) {
                    if (str_starts_with($kck, $prefix)) {
                        $nestedKnown[] = substr($kck, strlen($prefix));
                    }
                }
                // Also allow the key itself to act as a wildcard for direct children
                if (in_array($key, $knownChangedKeys, true)) {
                    $nestedKnown = array_keys($changesNested);
                }

                $result[$key] = $this->merge($existingNested, $changesNested, $nestedKnown);
                continue;
            }

            // Scalar change — only apply for known-changed keys
            if (in_array($key, $knownChangedKeys, true)) {
                $result[$key] = $value;
            }
            // else: preserve existing value silently
        }

        return $result;
    }

    /**
     * Render a PHP config array to a PHP file string using short array syntax.
     *
     * Produces:
     *   <?php
     *
     *   return [...];
     *
     * @param array<string, mixed> $config
     */
    public function renderPhpConfig(array $config): string
    {
        return "<?php\n\nreturn " . $this->renderValue($config, 0) . ";\n";
    }

    /** @param mixed $value */
    private function renderValue(mixed $value, int $depth): string
    {
        if (is_array($value)) {
            return $this->renderArray($value, $depth);
        }

        return var_export($value, true);
    }

    /**
     * @param array<mixed> $arr
     */
    private function renderArray(array $arr, int $depth): string
    {
        if ($arr === []) {
            return '[]';
        }

        $isList = array_is_list($arr);
        $pad = str_repeat('    ', $depth + 1);
        $closing = str_repeat('    ', $depth);

        $entries = [];
        foreach ($arr as $key => $value) {
            $rendered = $this->renderValue($value, $depth + 1);
            if ($isList) {
                $entries[] = $pad . $rendered;
            } else {
                $entries[] = $pad . var_export($key, true) . ' => ' . $rendered;
            }
        }

        return "[\n" . implode(",\n", $entries) . ",\n" . $closing . ']';
    }
}
