<?php

declare(strict_types=1);

namespace AppContainer\Rector\Rules\L8ToL9;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Covers breaking change: l9_rule_where_not_renamed
 *
 * @see \Tests\Unit\Rector\Rules\L8ToL9\WhereNotToWhereNotInRectorTest
 */
final class WhereNotToWhereNotInRector extends AbstractRector
{
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            '[l9_rule_where_not_renamed] Rename Rule::unique()->whereNot() to whereNotIn() and wrap the value argument in an array (Laravel 8 → 9).',
            [
                new CodeSample(
                    <<<'CODE_BEFORE'
use Illuminate\Validation\Rule;

$rules = [
    'email' => Rule::unique('users', 'email')->whereNot('status', 'inactive'),
];
CODE_BEFORE,
                    <<<'CODE_AFTER'
use Illuminate\Validation\Rule;

$rules = [
    'email' => Rule::unique('users', 'email')->whereNotIn('status', ['inactive']),
];
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
        return [MethodCall::class];
    }

    /**
     * @param MethodCall $node
     */
    public function refactor(Node $node): ?Node
    {
        if (! $this->isName($node->name, 'whereNot')) {
            return null;
        }

        // Must have exactly 2 args: (column, value)
        if (count($node->args) !== 2) {
            return null;
        }

        $secondArg = $node->args[1];

        if (! $secondArg instanceof Arg) {
            return null;
        }

        // Skip if the value is already an array (unexpected but guard anyway)
        if ($secondArg->value instanceof Array_) {
            return null;
        }

        // Rename method to whereNotIn
        $node->name = new Identifier('whereNotIn');

        // Wrap the single value in an array
        $node->args[1] = new Arg(new Array_([new ArrayItem($secondArg->value)]));

        return $node;
    }
}
