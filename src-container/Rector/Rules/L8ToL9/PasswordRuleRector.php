<?php

declare(strict_types=1);

namespace AppContainer\Rector\Rules\L8ToL9;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Covers breaking change: l9_password_rule_methods_renamed
 *
 * @see \Tests\Unit\Rector\Rules\L8ToL9\PasswordRuleRectorTest
 */
final class PasswordRuleRector extends AbstractRector
{
    /**
     * Maps old method names → new method names on Illuminate\Validation\Rules\Password.
     *
     * @var array<string, string>
     */
    private const RENAMED_METHODS = [
        'requireLetters'        => 'letters',
        'requireMixedCase'      => 'mixedCase',
        'requireNumbers'        => 'numbers',
        'requireSymbols'        => 'symbols',
        'requireUncompromised'  => 'uncompromised',
    ];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            '[l9_password_rule_methods_renamed] Rename Password rule methods: requireLetters() → letters(), requireMixedCase() → mixedCase(), requireNumbers() → numbers(), requireSymbols() → symbols(), requireUncompromised() → uncompromised() (Laravel 8 → 9).',
            [
                new CodeSample(
                    <<<'CODE_BEFORE'
use Illuminate\Validation\Rules\Password;

$rules = [
    'password' => Password::min(8)->requireLetters()->requireMixedCase()->requireNumbers()->requireSymbols(),
];
CODE_BEFORE,
                    <<<'CODE_AFTER'
use Illuminate\Validation\Rules\Password;

$rules = [
    'password' => Password::min(8)->letters()->mixedCase()->numbers()->symbols(),
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
        $currentName = $this->getName($node->name);

        if ($currentName === null) {
            return null;
        }

        if (! isset(self::RENAMED_METHODS[$currentName])) {
            return null;
        }

        $node->name = new Identifier(self::RENAMED_METHODS[$currentName]);

        return $node;
    }
}
