<?php

declare(strict_types=1);

namespace App\Rector\Rules\L8ToL9;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * @see \Tests\Unit\Rector\Rules\L8ToL9\ExampleRectorTest
 */
final class ExampleRector extends AbstractRector
{
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Describe the transformation: Laravel 8 → 9 change description here.',
            [
                new CodeSample(
                    <<<'CODE_BEFORE'
// Before: Laravel 8 usage
$result = $this->oldMethodName($argument);
CODE_BEFORE,
                    <<<'CODE_AFTER'
// After: Laravel 9 usage
$result = $this->newMethodName($argument);
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
        // Use the narrowest possible node type
        // Common types: MethodCall, StaticCall, FuncCall, ClassMethod, New_
        return [MethodCall::class];
    }

    public function refactor(Node $node): ?Node
    {
        // Guard: return null to skip (no transformation)
        if (! $this->isName($node->name, 'oldMethodName')) {
            return null;
        }

        // Guard: optionally check the object type
        // if (! $this->isObjectType($node->var, new ObjectType('App\SomeClass'))) {
        //     return null;
        // }

        // Apply transformation
        $node->name = new Node\Identifier('newMethodName');

        return $node;
    }
}
