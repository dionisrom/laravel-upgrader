<?php

declare(strict_types=1);

namespace AppContainer\Composer;

use AppContainer\Composer\Exception\CodeUpdateRequiredException;

/**
 * PackageReplacementValidator — Validates that code using replaced packages is updated.
 *
 * This class ensures that when a package is replaced:
 * 1. All usages of the old package in the codebase are identified
 * 2. Rector rules are available to automatically update the code
 * 3. If no Rector rules exist, manual code updates are flagged for review
 * 4. The upgrade cannot proceed until code is updated or explicitly approved
 */
final class PackageReplacementValidator
{
    /** @var array<string, list<string>> Map of package name to Rector rule classes */
    private array $packageRectorRules = [];

    /** @var array<string, list<string>> Map of package name to namespace patterns */
    private array $packageNamespacePatterns = [];

    public function __construct(
        private readonly string $workspacePath,
        private readonly ?string $packageRulesDir = null,
    ) {
        $this->loadPackageRules();
    }

    /**
     * Validate that all package replacements have corresponding code updates.
     *
     * @param list<DependencyReplacement> $replacements
     * @throws CodeUpdateRequiredException if code updates are missing
     */
    public function validateReplacements(array $replacements): void
    {
        $unhandledReplacements = [];

        foreach ($replacements as $replacement) {
            if (!$this->isCodeUpdateComplete($replacement)) {
                $unhandledReplacements[] = $replacement;
            }
        }

        if ($unhandledReplacements !== []) {
            throw CodeUpdateRequiredException::fromUnhandledReplacements($unhandledReplacements);
        }
    }

    /**
     * Check if a specific replacement has been fully handled.
     */
    public function isCodeUpdateComplete(DependencyReplacement $replacement): bool
    {
        // If Rector rules are available, assume they will handle the update
        if ($replacement->rectorRulesAvailable) {
            return true;
        }

        // Check if there are any usages of the old package in the codebase
        $usages = $this->findPackageUsages($replacement->oldPackage);

        if ($usages === []) {
            // No usages found, replacement is safe
            return true;
        }

        // Check if manual approval was granted for this replacement
        if ($this->hasManualApproval($replacement)) {
            return true;
        }

        return false;
    }

    /**
     * Find all usages of a package in the codebase.
     *
     * @return list<array<file: string, line: int, code: string>>
     */
    public function findPackageUsages(string $package): array
    {
        $usages = [];
        $patterns = $this->getNamespacePatterns($package);

        // Search for imports and references
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->workspacePath . '/app'),
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            if ($content === false) {
                continue;
            }

            foreach ($patterns as $pattern) {
                if (str_contains($content, $pattern)) {
                    $lines = explode("\n", $content);
                    foreach ($lines as $lineNum => $line) {
                        if (str_contains($line, $pattern)) {
                            $usages[] = [
                                'file' => $file->getPathname(),
                                'line' => $lineNum + 1,
                                'code' => trim($line),
                                'pattern' => $pattern,
                            ];
                        }
                    }
                }
            }
        }

        return $usages;
    }

    /**
     * Check if Rector rules exist for a package replacement.
     */
    public function hasRectorRules(string $package, ?string $hop = null): bool
    {
        if (isset($this->packageRectorRules[$package])) {
            return $this->packageRectorRules[$package] !== [];
        }

        // Check package-rules configuration
        $ruleFile = $this->getPackageRuleFile($package);
        if ($ruleFile === null || !file_exists($ruleFile)) {
            return false;
        }

        $config = json_decode(file_get_contents($ruleFile), true);
        if (!is_array($config) || !isset($config['hops'])) {
            return false;
        }

        // If hop is specified, check that specific hop
        if ($hop !== null) {
            $hopKey = str_replace('-', '_', $hop);
            if (isset($config['hops'][$hopKey]['rules'])) {
                return $config['hops'][$hopKey]['rules'] !== [];
            }
            return false;
        }

        // Check all hops for any rules
        foreach ($config['hops'] as $hopConfig) {
            if (isset($hopConfig['rules']) && $hopConfig['rules'] !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the Rector rules available for a package on a specific hop.
     *
     * @return list<string>
     */
    public function getRectorRules(string $package, string $hop): array
    {
        $ruleFile = $this->getPackageRuleFile($package);
        if ($ruleFile === null || !file_exists($ruleFile)) {
            return [];
        }

        $config = json_decode(file_get_contents($ruleFile), true);
        if (!is_array($config) || !isset($config['hops'])) {
            return [];
        }

        $hopKey = str_replace('-', '_', $hop);
        if (isset($config['hops'][$hopKey]['rules'])) {
            /** @var list<string> $rules */
            $rules = $config['hops'][$hopKey]['rules'];
            return $rules;
        }

        return [];
    }

    /**
     * Generate a report of what code updates are needed for a replacement.
     *
     * @return array<string, mixed>
     */
    public function generateCodeUpdateReport(DependencyReplacement $replacement): array
    {
        $usages = $this->findPackageUsages($replacement->oldPackage);
        $rectorRules = $this->getRectorRules(
            $replacement->oldPackage,
            'hop-8-to-9', // TODO: Make this dynamic
        );

        return [
            'old_package' => $replacement->oldPackage,
            'new_package' => $replacement->newPackage,
            'usages_found' => count($usages),
            'usages' => $usages,
            'rector_rules_available' => $rectorRules !== [],
            'rector_rules' => $rectorRules,
            'manual_update_required' => $rectorRules === [] && $usages !== [],
            'recommendations' => $this->generateRecommendations($replacement, $usages, $rectorRules),
        ];
    }

    private function loadPackageRules(): void
    {
        $this->packageRectorRules = [];
        $this->packageNamespacePatterns = [];

        // Known package namespace patterns
        $this->packageNamespacePatterns = [
            'facade/ignition' => ['Facade\\Ignition', 'Facade\\Flare'],
            'swiftmailer/swiftmailer' => ['Swift_', 'SwiftMailer'],
            'fzaninotto/faker' => ['Faker\\Factory', 'Faker\\Generator'],
            'laravelcollective/html' => ['Collective\\Html'],
            'fruitcake/laravel-cors' => ['Fruitcake\\Cors'],
        ];
    }

    /**
     * @return list<string>
     */
    private function getNamespacePatterns(string $package): array
    {
        $normalized = strtolower($package);

        // Check for known patterns
        if (isset($this->packageNamespacePatterns[$normalized])) {
            return $this->packageNamespacePatterns[$normalized];
        }

        // Generate patterns from package name
        // e.g., "vendor/package-name" -> "Vendor\\PackageName"
        $parts = explode('/', $normalized);
        if (count($parts) === 2) {
            $vendor = $this->toPascalCase($parts[0]);
            $name = $this->toPascalCase(str_replace('-', '_', $parts[1]));
            return ["{$vendor}\\\\{$name}"];
        }

        return [];
    }

    private function toPascalCase(string $str): string
    {
        return str_replace(['-', '_'], '', ucwords($str, '-_'));
    }

    private function getPackageRuleFile(string $package): ?string
    {
        if ($this->packageRulesDir === null) {
            return null;
        }

        $normalized = str_replace('/', '-', strtolower($package));
        $path = $this->packageRulesDir . '/' . $normalized . '.json';

        return file_exists($path) ? $path : null;
    }

    private function hasManualApproval(DependencyReplacement $replacement): bool
    {
        // Check for manual approval file
        $approvalFile = $this->workspacePath . '/.upgrader/manual-approvals.json';
        if (!file_exists($approvalFile)) {
            return false;
        }

        $content = file_get_contents($approvalFile);
        if ($content === false) {
            return false;
        }

        $approvals = json_decode($content, true);
        if (!is_array($approvals)) {
            return false;
        }

        foreach ($approvals as $approval) {
            if (is_array($approval
                && isset($approval['package'])
                && $approval['package'] === $replacement->oldPackage
                && ($approval['approved'] ?? false) === true
            )) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<file: string, line: int, code: string>> $usages
     * @param list<string> $rectorRules
     * @return list<string>
     */
    private function generateRecommendations(
        DependencyReplacement $replacement,
        array $usages,
        array $rectorRules,
    ): array {
        $recommendations = [];

        if ($usages === []) {
            $recommendations[] = "No usages of {$replacement->oldPackage} found in the codebase.";
            return $recommendations;
        }

        if ($rectorRules !== []) {
            $recommendations[] = "Rector rules are available to automatically update code:";
            foreach ($rectorRules as $rule) {
                $recommendations[] = "  - {$rule}";
            }
            $recommendations[] = "Run the Rector rules to update your code automatically.";
        } else {
            $recommendations[] = "No Rector rules available for {$replacement->oldPackage}.";
            $recommendations[] = "Manual code updates are required for the following usages:";
            foreach (array_slice($usages, 0, 5) as $usage) {
                $recommendations[] = "  - {$usage['file']}:{$usage['line']} - {$usage['code']}";
            }
            if (count($usages) > 5) {
                $recommendations[] = "  ... and " . (count($usages) - 5) . " more usages.";
            }
        }

        return $recommendations;
    }
}
