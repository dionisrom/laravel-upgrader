<?php

declare(strict_types=1);

namespace AppContainer\Rector\Rules\L8ToL9;

use PhpParser\Node;
use PhpParser\Node\Scalar\String_;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Covers breaking change: l9_throttle_rate_limiter_api
 *
 * @see \Tests\Unit\Rector\Rules\L8ToL9\HttpKernelMiddlewareRectorTest
 */
final class HttpKernelMiddlewareRector extends AbstractRector
{
    private const OLD_THROTTLE = 'throttle:6,1';

    private const NEW_THROTTLE = 'throttle:api';

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            '[l9_throttle_rate_limiter_api] Replace legacy "throttle:6,1" middleware string with "throttle:api" named rate limiter (Laravel 8 → 9).',
            [
                new CodeSample(
                    <<<'CODE_BEFORE'
class Kernel extends \Illuminate\Foundation\Http\Kernel
{
    protected $middlewareGroups = [
        'api' => [
            'throttle:6,1',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],
    ];
}
CODE_BEFORE,
                    <<<'CODE_AFTER'
class Kernel extends \Illuminate\Foundation\Http\Kernel
{
    protected $middlewareGroups = [
        'api' => [
            'throttle:api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],
    ];
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
        return [String_::class];
    }

    /**
     * @param String_ $node
     */
    public function refactor(Node $node): ?Node
    {
        if ($node->value !== self::OLD_THROTTLE) {
            return null;
        }

        $node->value = self::NEW_THROTTLE;

        return $node;
    }
}
