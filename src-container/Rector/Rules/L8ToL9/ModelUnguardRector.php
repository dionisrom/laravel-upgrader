<?php

declare(strict_types=1);

namespace AppContainer\Rector\Rules\L8ToL9;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeTraverser;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Covers breaking change: l9_model_unguard_deprecated
 *
 * @see \Tests\Unit\Rector\Rules\L8ToL9\ModelUnguardRectorTest
 */
final class ModelUnguardRector extends AbstractRector
{
    /** @var string[] */
    private const UNGUARD_METHODS = ['unguard', 'reguard'];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            '[l9_model_unguard_deprecated] Remove Model::unguard() and Model::reguard() static calls — use per-model $guarded = [] or forceFill() instead (Laravel 8 → 9).',
            [
                new CodeSample(
                    <<<'CODE_BEFORE'
use Illuminate\Database\Eloquent\Model;

Model::unguard();

$user = new User();
$user->fill(['name' => 'test']);

Model::reguard();
CODE_BEFORE,
                    <<<'CODE_AFTER'
use Illuminate\Database\Eloquent\Model;

$user = new User();
$user->fill(['name' => 'test']);
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
        return [Expression::class];
    }

    /**
     * @param Expression $node
     * @return int|null
     */
    public function refactor(Node $node)
    {
        if (! $node->expr instanceof StaticCall) {
            return null;
        }

        $staticCall = $node->expr;

        if (! $this->isNames($staticCall->name, self::UNGUARD_METHODS)) {
            return null;
        }

        return NodeTraverser::REMOVE_NODE;
    }
}
