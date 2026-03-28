<?php

declare(strict_types=1);

namespace AppContainer\Rector\Rules\Package;

/**
 * Base class for per-package Rector rule set descriptors.
 *
 * Concrete subclasses declare which Rector rule classes apply for each upgrade hop.
 * These PHP classes serve as the type-safe, IDE-navigable registry of package rules.
 * The JSON version matrices in config/package-rules/ reference these same FQCNs.
 */
abstract class AbstractPackageRuleSet
{
    /**
     * The Composer package name that this rule set targets, e.g. "livewire/livewire".
     */
    abstract public function getPackageName(): string;

    /**
     * Return the Rector rule FQCN class-strings applicable for the given hop.
     *
     * @param string $hop  Hop identifier, e.g. "9-to-10"
     * @return list<string> Fully-qualified class names of applicable Rector rules
     */
    abstract public function getRuleClasses(string $hop): array;

    /**
     * Whether this rule set applies to the given package name.
     */
    public function isApplicable(string $packageName): bool
    {
        return $this->getPackageName() === $packageName;
    }

    /**
     * Return all rule classes across all supported hops (for discovery/validation).
     *
     * @return list<string>
     */
    public function getAllRuleClasses(): array
    {
        $all = [];

        foreach ($this->supportedHops() as $hop) {
            foreach ($this->getRuleClasses($hop) as $class) {
                if (! in_array($class, $all, true)) {
                    $all[] = $class;
                }
            }
        }

        return $all;
    }

    /**
     * List all hop identifiers this rule set has rules for.
     *
     * @return list<string>
     */
    abstract public function supportedHops(): array;
}
