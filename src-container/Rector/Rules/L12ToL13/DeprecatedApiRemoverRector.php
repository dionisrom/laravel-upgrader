<?php

declare(strict_types=1);

namespace AppContainer\Rector\Rules\L12ToL13;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeVisitor;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Covers breaking changes: Model::unguard() / Model::reguard() removal in Laravel 13.
 *
 * These static methods were deprecated in earlier versions and removed in Laravel 13.
 * The recommended replacement is to use $model->forceFill($data) instead of the
 * unguard/fill/reguard pattern.
 *
 * This rule removes the unguard()/reguard() calls entirely.
 * It cannot automatically convert to forceFill() because the fill() call may be
 * distant from the unguard/reguard calls.
 *
 * @see \Tests\Unit\Rector\Rules\L12ToL13\DeprecatedApiRemoverRectorTest
 */
final class DeprecatedApiRemoverRector extends AbstractRector
{
    /** @var array<string, string> */
    private const DEPRECATED_STATIC_METHODS = [
        'unguard' => 'Model::unguard() was removed in Laravel 13. Use $model->forceFill($data) instead of the unguard/fill/reguard pattern.',
        'reguard' => 'Model::reguard() was removed in Laravel 13. Use $model->forceFill($data) instead of the unguard/fill/reguard pattern.',
    ];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            '[l13_deprecated_api_removal] Remove Model::unguard()/reguard() calls removed in Laravel 13.',
            [
                new CodeSample(
                    <<<'CODE_BEFORE'
use Illuminate\Database\Eloquent\Model;

Model::unguard();
$order->fill($data);
Model::reguard();
CODE_BEFORE,
                    <<<'CODE_AFTER'
use Illuminate\Database\Eloquent\Model;

$order->fill($data);
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
     */
    public function refactor(Node $node): ?int
    {
        $expr = $node->expr;

        if (! $expr instanceof StaticCall) {
            return null;
        }

        if (! $expr->name instanceof Identifier) {
            return null;
        }

        $methodName = $expr->name->toString();

        if (! isset(self::DEPRECATED_STATIC_METHODS[$methodName])) {
            return null;
        }

        if (! $this->isModelClass($expr->class)) {
            return null;
        }

        // Remove the deprecated static call statement entirely.
        // Model::unguard()/reguard() have no purpose in L13 — the methods were removed.
        return NodeVisitor::REMOVE_NODE;
    }

    private function isModelClass(Node $class): bool
    {
        // Match both short name (Model) and FQCN — isName handles resolution
        if ($this->isName($class, 'Illuminate\Database\Eloquent\Model')) {
            return true;
        }

        if ($this->isName($class, 'Model')) {
            return true;
        }

        return false;
    }
}
