<?php

declare(strict_types=1);

namespace AppContainer\Rector\Rules\Package\Livewire;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Livewire V2 → V3: rename `$this->emit()` / `$this->emitSelf()` to `$this->dispatch()`.
 *
 * In Livewire V3 the event system was renamed wholesale:
 *   - emit()      → dispatch()
 *   - emitSelf()  → dispatch()   (dispatch to self is the default in V3)
 *
 * `emitTo()` and `emitUp()` require different handling (different signature / removed)
 * and are deliberately excluded from auto-fix — they are flagged by other rules.
 *
 * @see \Tests\Unit\Rector\Rules\Package\Livewire\EmitToDispatchRectorTest
 */
final class EmitToDispatchRector extends AbstractRector
{
    /**
     * Methods that map directly to dispatch() with identical arguments.
     *
     * @var array<string, string>
     */
    private const RENAME_MAP = [
        'emit'     => 'dispatch',
        'emitSelf' => 'dispatch',
    ];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            '[livewire_v3_emit_renamed] Replace $this->emit() / $this->emitSelf() with $this->dispatch() — Livewire V2 → V3 event system rename.',
            [
                new CodeSample(
                    <<<'CODE_BEFORE'
use Livewire\Component;

class OrderComponent extends Component
{
    public function placeOrder(): void
    {
        // ... order logic
        $this->emit('order.placed', $this->orderId);
        $this->emitSelf('refreshComponent');
    }
}
CODE_BEFORE,
                    <<<'CODE_AFTER'
use Livewire\Component;

class OrderComponent extends Component
{
    public function placeOrder(): void
    {
        // ... order logic
        $this->dispatch('order.placed', $this->orderId);
        $this->dispatch('refreshComponent');
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
        // Only transform $this->methodName() calls.
        if (! ($node->var instanceof Variable)) {
            return null;
        }

        if ($node->var->name !== 'this') {
            return null;
        }

        if (! $this->isLivewireComponentContext()) {
            return null;
        }

        $methodName = $this->getName($node->name);
        if ($methodName === null) {
            return null;
        }

        $newName = self::RENAME_MAP[$methodName] ?? null;
        if ($newName === null) {
            return null;
        }

        if ($methodName === $newName) {
            return null; // Already correct — idempotent guard.
        }

        $node->name = new Identifier($newName);

        return $node;
    }

    private function isLivewireComponentContext(): bool
    {
        $fileContent = $this->file->getFileContent();

        if (preg_match('/extends\s+\\\\?Livewire\\\\Component\b/', $fileContent) === 1) {
            return true;
        }

        if (preg_match('/use\s+Livewire\\\\Component(?:\s+as\s+(\w+))?\s*;/', $fileContent, $matches) !== 1) {
            return false;
        }

        $alias = $matches[1] ?? 'Component';

        return preg_match('/extends\s+' . preg_quote($alias, '/') . '\b/', $fileContent) === 1;
    }
}
