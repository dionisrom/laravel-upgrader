<?php

declare(strict_types=1);

namespace AppContainer\Rector\Rules\L9ToL10;

use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Class_;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Covers breaking change: l10_native_return_types_added
 *
 * Laravel 10 added native PHP return types to all framework classes.
 * Any application class that extends a framework class and overrides
 * a method WITHOUT a return type declaration will cause a PHP fatal error.
 *
 * This rule adds the missing return type declarations to the most
 * commonly overridden framework methods.
 *
 * @see \Tests\Unit\Rector\Rules\L9ToL10\LaravelModelReturnTypeRectorTest
 */
final class LaravelModelReturnTypeRector extends AbstractRector
{
    /**
     * Maps fully-qualified parent class → method name → PHP return type.
     *
     * Only covers methods that are commonly overridden in application code
     * and had native return types added in Laravel 10.
     *
     * @var array<string, array<string, string>>
     */
    private const CLASS_METHOD_RETURN_TYPES = [
        // Eloquent Model
        'Illuminate\Database\Eloquent\Model' => [
            'toArray'       => 'array',
            'jsonSerialize' => 'mixed',
            'boot'          => 'void',
            'booted'        => 'void',
        ],
        // Short-name fallback (when use-import not resolved to FQN)
        'Model' => [
            'toArray'       => 'array',
            'jsonSerialize' => 'mixed',
            'boot'          => 'void',
            'booted'        => 'void',
        ],
        // Form Request
        'Illuminate\Foundation\Http\FormRequest' => [
            'rules'     => 'array',
            'authorize' => 'bool',
        ],
        'FormRequest' => [
            'rules'     => 'array',
            'authorize' => 'bool',
        ],
        // Service Provider
        'Illuminate\Support\ServiceProvider' => [
            'boot'     => 'void',
            'register' => 'void',
        ],
        'ServiceProvider' => [
            'boot'     => 'void',
            'register' => 'void',
        ],
        // Exception Handler
        'Illuminate\Foundation\Exceptions\Handler' => [
            'register' => 'void',
        ],
        'Handler' => [
            'register' => 'void',
        ],
    ];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            '[l10_native_return_types_added] Add missing native return types to overridden framework methods — Laravel 10 added return types to all framework classes (Laravel 9 → 10).',
            [
                new CodeSample(
                    <<<'CODE_BEFORE'
use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    public function toArray()
    {
        return array_merge(parent::toArray(), ['extra' => true]);
    }

    protected static function boot()
    {
        parent::boot();
        static::creating(function (self $model): void {
            $model->uuid = \Str::uuid();
        });
    }
}
CODE_BEFORE,
                    <<<'CODE_AFTER'
use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    public function toArray(): array
    {
        return array_merge(parent::toArray(), ['extra' => true]);
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $model): void {
            $model->uuid = \Str::uuid();
        });
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

        $parentClassName = $this->getName($node->extends);

        $returnTypeMap = self::CLASS_METHOD_RETURN_TYPES[$parentClassName] ?? null;
        if ($returnTypeMap === null) {
            return null;
        }

        $changed = false;

        foreach ($node->getMethods() as $classMethod) {
            // Skip private methods — they can never conflict with parent signatures
            if ($classMethod->isPrivate()) {
                continue;
            }

            // Already has a return type — nothing to add
            if ($classMethod->returnType !== null) {
                continue;
            }

            $methodName = $this->getName($classMethod);

            if (! isset($returnTypeMap[$methodName])) {
                continue;
            }

            $classMethod->returnType = new Identifier($returnTypeMap[$methodName]);
            $changed = true;
        }

        return $changed ? $node : null;
    }
}
