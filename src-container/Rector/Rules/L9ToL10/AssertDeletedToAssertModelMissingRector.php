<?php

declare(strict_types=1);

namespace AppContainer\Rector\Rules\L9ToL10;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Covers breaking change: l10_assert_deleted_removed
 *
 * assertDeleted() was deprecated in Laravel 9 and removed in Laravel 10.
 * The replacement is assertModelMissing() for model-based assertions.
 * Table-based assertDeleted($table, $data) calls are left unchanged (use assertDatabaseMissing).
 *
 * @see \Tests\Unit\Rector\Rules\L9ToL10\AssertDeletedToAssertModelMissingRectorTest
 */
final class AssertDeletedToAssertModelMissingRector extends AbstractRector
{
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            '[l10_assert_deleted_removed] Replace $this->assertDeleted($model) with $this->assertModelMissing($model) — assertDeleted() was removed in Laravel 10 (Laravel 9 → 10).',
            [
                new CodeSample(
                    <<<'CODE_BEFORE'
use Tests\TestCase;
use App\Models\User;

class UserTest extends TestCase
{
    public function test_user_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $user->delete();

        $this->assertDeleted($user);
    }
}
CODE_BEFORE,
                    <<<'CODE_AFTER'
use Tests\TestCase;
use App\Models\User;

class UserTest extends TestCase
{
    public function test_user_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $user->delete();

        $this->assertModelMissing($user);
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
        return [MethodCall::class];
    }

    /**
     * @param MethodCall $node
     */
    public function refactor(Node $node): ?Node
    {
        if (! $this->isName($node->name, 'assertDeleted')) {
            return null;
        }

        // assertDeleted($table, $data) — first arg is a string (table name)
        // This maps to assertDatabaseMissing(), leave for manual review.
        $args = $node->getArgs();
        if (isset($args[0]) && $args[0]->value instanceof String_) {
            return null;
        }

        // assertDeleted($model) → assertModelMissing($model)
        $node->name = new Identifier('assertModelMissing');

        // assertModelMissing() accepts only one argument — drop any extras
        if (count($args) > 1) {
            $node->args = [$args[0]];
        }

        return $node;
    }
}
