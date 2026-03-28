<?php

declare(strict_types=1);

namespace AppContainer\Rector\Rules\Package\Livewire;

use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\Class_;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Livewire V2 → V3: convert `getXxxProperty()` computed property methods to `#[Computed]` attribute.
 *
 * In Livewire V3, the V2 computed property convention (`getXxxProperty()`) was superseded
 * by a PHP 8 attribute `#[\Livewire\Attributes\Computed]` on a renamed method.
 *
 * Transformation:
 *   public function getTotalProperty(): float  → #[Computed] public function total(): float
 *
 * Steps performed:
 *   1. Find `ClassMethod` nodes matching /^get([A-Z][a-zA-Z]*)Property$/ inside a Livewire class.
 *   2. Rename the method: strip `get` prefix + `Property` suffix, lowercase first character.
 *   3. Add `#[\Livewire\Attributes\Computed]` attribute to the method.
 *
 * Only operates on classes that extend Livewire\Component (short or FQ name).
 *
 * @see \Tests\Unit\Rector\Rules\Package\Livewire\ComputedPropertyRectorTest
 */
final class ComputedPropertyRector extends AbstractRector
{
    /** @var list<string> Recognized short/FQ parent class names for Livewire components. */
    private const LIVEWIRE_PARENT_CLASSES = [
        'Component',
        'Livewire\\Component',
        '\\Livewire\\Component',
    ];

    private const COMPUTED_FQCN = 'Livewire\\Attributes\\Computed';

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            '[livewire_v3_computed_property] Convert getXxxProperty() methods to #[Computed] attribute (Livewire V2 → V3).',
            [
                new CodeSample(
                    <<<'CODE_BEFORE'
use Livewire\Component;

class OrderSummary extends Component
{
    public function getTotalProperty(): float
    {
        return $this->items->sum('price');
    }
}
CODE_BEFORE,
                    <<<'CODE_AFTER'
use Livewire\Component;

class OrderSummary extends Component
{
    #[\Livewire\Attributes\Computed]
    public function total(): float
    {
        return $this->items->sum('price');
    }
}
CODE_AFTER
                ),
            ]
        );
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [Class_::class];
    }

    /**
     * @param Class_ $node
     */
    public function refactor(Node $node): ?Node
    {
        if ($node->extends === null) {
            return null;
        }

        $parentName = $this->getName($node->extends);

        if (! $this->isLivewireComponent($parentName)) {
            return null;
        }

        $changed = false;

        foreach ($node->getMethods() as $classMethod) {
            $methodName = $this->getName($classMethod);

            if (! preg_match('/^get([A-Z][a-zA-Z]*)Property$/', $methodName, $matches)) {
                continue;
            }

            // Already has #[Computed] — idempotent guard.
            if ($this->hasComputedAttribute($classMethod->attrGroups)) {
                continue;
            }

            // Rename: getTotalProperty → total
            $newName = lcfirst($matches[1]);
            $classMethod->name = new \PhpParser\Node\Identifier($newName);

            // Add #[\Livewire\Attributes\Computed] attribute.
            $attribute = new Attribute(new FullyQualified(self::COMPUTED_FQCN));
            $classMethod->attrGroups[] = new AttributeGroup([$attribute]);

            $changed = true;
        }

        return $changed ? $node : null;
    }

    private function isLivewireComponent(string $parentName): bool
    {
        return in_array($parentName, self::LIVEWIRE_PARENT_CLASSES, true)
            || str_ends_with($parentName, '\\Component');
    }

    /**
     * @param list<AttributeGroup> $attrGroups
     */
    private function hasComputedAttribute(array $attrGroups): bool
    {
        foreach ($attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                $name = $this->getName($attr->name);
                if ($name !== null && str_contains($name, 'Computed')) {
                    return true;
                }
            }
        }

        return false;
    }
}
