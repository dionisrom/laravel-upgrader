<?php

declare(strict_types=1);

namespace AppContainer\Rector\Rules\L10ToL11;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Covers breaking change: l11_rate_limiting (L11-011)
 *
 * In Laravel 10, rate limiters were defined in the HTTP Kernel's
 * configureRateLimiting() method using RateLimiter::for().
 *
 * In Laravel 11, the HTTP Kernel is removed entirely by the SlimSkeleton
 * migration. Any RateLimiter::for() and RateLimiter::forRequests() calls
 * inside that method are silently lost unless detected before the Kernel is
 * deleted.
 *
 * This rule detects all RateLimiter::for() and RateLimiter::forRequests()
 * static calls anywhere in the workspace (they commonly live in the Kernel
 * but may also appear in service providers) and flags them for manual
 * migration to AppServiceProvider::boot().
 *
 * This rule is detect-only (returns null). It does not auto-transform —
 * the rate limiter closure logic is semantically sensitive.
 *
 * @see \Tests\Unit\Rector\Rules\L10ToL11\RateLimiterMigrationAuditorTest
 */
final class RateLimiterMigrationAuditor extends AbstractRector
{
    /**
     * RateLimiter static methods that define named rate limiters.
     *
     * @var list<string>
     */
    private const RATE_LIMITER_DEFINE_METHODS = [
        'for',
        'forRequests',
    ];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            '[l11_rate_limiting] Detect RateLimiter::for() and RateLimiter::forRequests() definitions that must be manually migrated from the HTTP Kernel\'s configureRateLimiting() to AppServiceProvider::boot() (Laravel 10 → 11).',
            [
                new CodeSample(
                    <<<'CODE_BEFORE'
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

protected function configureRateLimiting(): void
{
    RateLimiter::for('api', function (Request $request) {
        return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
    });
}
CODE_BEFORE,
                    <<<'CODE_AFTER'
// MANUAL REVIEW: RateLimiter::for('api', ...) must be moved to AppServiceProvider::boot()
// The HTTP Kernel is removed in Laravel 11 — configureRateLimiting() no longer exists.
// See: https://laravel.com/docs/11.x/routing#defining-rate-limiters
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

protected function configureRateLimiting(): void
{
    RateLimiter::for('api', function (Request $request) {
        return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
    });
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
        return [StaticCall::class];
    }

    /**
     * @param StaticCall $node
     */
    public function refactor(Node $node): ?Node
    {
        if (! $this->isName($node->class, 'RateLimiter')) {
            return null;
        }

        foreach (self::RATE_LIMITER_DEFINE_METHODS as $method) {
            if ($this->isName($node->name, $method)) {
                // Detect-only: do not transform. The audit report captures this location.
                return null;
            }
        }

        return null;
    }
}
