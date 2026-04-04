<?php

declare(strict_types=1);

namespace App\Composer;

final class PhpConstraintDetector
{
    public function detect(string $workspacePath): ?string
    {
        $composerJsonPath = rtrim($workspacePath, '/\\') . DIRECTORY_SEPARATOR . 'composer.json';

        if (!is_file($composerJsonPath)) {
            return null;
        }

        $contents = file_get_contents($composerJsonPath);
        if ($contents === false) {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return null;
        }

        $require = $decoded['require'] ?? null;
        if (!is_array($require)) {
            return null;
        }

        $constraint = $require['php'] ?? null;
        if (!is_string($constraint) || trim($constraint) === '') {
            return null;
        }

        return trim($constraint);
    }

    /**
     * @param list<string> $supportedPhpBases
     */
    public function selectSupportedPhpBase(string $constraint, array $supportedPhpBases): ?string
    {
        $candidates = array_values(array_unique($supportedPhpBases));
        usort($candidates, 'version_compare');

        foreach ($candidates as $candidate) {
            if ($this->matchesConstraint($candidate, $constraint)) {
                return $candidate;
            }
        }

        return null;
    }

    public function matchesConstraint(string $version, string $constraint): bool
    {
        $normalizedVersion = $this->normalizeVersion($version);
        $orGroups = preg_split('/\s*(?:\|\|?|or)\s*/i', trim($constraint)) ?: [];

        foreach ($orGroups as $group) {
            if ($group === '') {
                continue;
            }

            if ($this->matchesAllTokens($normalizedVersion, $group)) {
                return true;
            }
        }

        return false;
    }

    private function matchesAllTokens(string $version, string $group): bool
    {
        preg_match_all('/(?:\^|~|>=|<=|>|<|=)?\s*\d+(?:\.\d+){0,2}(?:\.\*)?/', $group, $matches);
        $tokens = $matches[0] ?? [];

        if ($tokens === []) {
            return false;
        }

        foreach ($tokens as $token) {
            if (!$this->matchesToken($version, preg_replace('/\s+/', '', $token) ?? '')) {
                return false;
            }
        }

        return true;
    }

    private function matchesToken(string $version, string $token): bool
    {
        if ($token === '') {
            return true;
        }

        preg_match('/^(\^|~|>=|<=|>|<|=)?(.*)$/', $token, $parts);
        $operator = $parts[1] ?? '';
        $operand = $parts[2] ?? '';

        if (str_ends_with($operand, '.*')) {
            $prefix = substr($operand, 0, -2);
            return str_starts_with($this->normalizeVersion($version), $prefix . '.');
        }

        $normalizedOperand = $this->normalizeVersion($operand);

        return match ($operator) {
            '^' => version_compare($version, $normalizedOperand, '>=')
                && version_compare($version, $this->caretUpperBound($operand), '<'),
            '~' => version_compare($version, $normalizedOperand, '>=')
                && version_compare($version, $this->tildeUpperBound($operand), '<'),
            '>=' => version_compare($version, $normalizedOperand, '>='),
            '<=' => version_compare($version, $normalizedOperand, '<='),
            '>' => version_compare($version, $normalizedOperand, '>'),
            '<' => version_compare($version, $normalizedOperand, '<'),
            '=', '' => $this->matchesExactToken($version, $operand),
            default => false,
        };
    }

    private function matchesExactToken(string $version, string $operand): bool
    {
        $parts = explode('.', $operand);
        $normalizedOperand = $this->normalizeVersion($operand);

        if (count($parts) <= 2) {
            $operandPrefix = implode('.', array_slice(explode('.', $normalizedOperand), 0, count($parts)));
            return $normalizedVersion === $normalizedOperand
                || str_starts_with($normalizedVersion, $operandPrefix . '.');
        }

        return version_compare($version, $normalizedOperand, '==');
    }

    private function normalizeVersion(string $version): string
    {
        $parts = array_map('intval', explode('.', $version));
        while (count($parts) < 3) {
            $parts[] = 0;
        }

        return implode('.', array_slice($parts, 0, 3));
    }

    private function caretUpperBound(string $operand): string
    {
        $parts = array_map('intval', explode('.', $this->normalizeVersion($operand)));

        if ($parts[0] > 0) {
            return ($parts[0] + 1) . '.0.0';
        }

        if ($parts[1] > 0) {
            return '0.' . ($parts[1] + 1) . '.0';
        }

        return '0.0.' . ($parts[2] + 1);
    }

    private function tildeUpperBound(string $operand): string
    {
        $rawParts = explode('.', $operand);
        $parts = array_map('intval', explode('.', $this->normalizeVersion($operand)));

        if (count($rawParts) >= 3) {
            return $parts[0] . '.' . ($parts[1] + 1) . '.0';
        }

        return ($parts[0] + 1) . '.0.0';
    }
}