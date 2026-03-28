<?php

declare(strict_types=1);

namespace App\Report\Renderer;

/**
 * Renders Rector rule names as inline annotation badges.
 */
final class AnnotationRenderer
{
    /**
     * Render a list of Rector rule identifiers as annotation badges.
     *
     * @param list<string> $rules
     */
    public function render(array $rules): string
    {
        if ($rules === []) {
            return '';
        }

        $badges = '';
        foreach ($rules as $rule) {
            $ruleEsc  = htmlspecialchars($rule, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $shortName = $this->shortName($rule);
            $shortEsc  = htmlspecialchars($shortName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $badges .= <<<HTML
            <span class="rule-badge" title="{$ruleEsc}" aria-label="Rector rule: {$ruleEsc}">{$shortEsc}</span>
            HTML;
        }

        return '<div class="rule-annotations" aria-label="Applied Rector rules">' . $badges . '</div>';
    }

    /**
     * Extract the short class name from a fully-qualified rule name.
     * E.g. "Rector\Laravel\Rector\Class_\AddParentRegisterMiddlewareCallRector" → "AddParentRegisterMiddlewareCallRector"
     */
    private function shortName(string $rule): string
    {
        $parts = explode('\\', $rule);
        return (string) end($parts);
    }
}
