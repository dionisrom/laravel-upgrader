<?php

declare(strict_types=1);

namespace AppContainer\Rector\Rules\Package\Filament;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * filament/filament V2 → V3: rename Form and Table classes to new namespaces.
 *
 * In Filament V3, the shared `Form` and `Table` classes used in Resource definitions
 * were moved to dedicated packages:
 *
 *   Filament\Resources\Form  → Filament\Forms\Form
 *   Filament\Resources\Table → Filament\Tables\Table
 *
 * This is a mechanical namespace rename that can be auto-applied.
 *
 * @see \Tests\Unit\Rector\Rules\Package\Filament\FilamentFormTableNamespaceRectorTest
 */
final class FilamentFormTableNamespaceRector extends AbstractRector
{
    /**
     * Old FQCN → new FQCN.
     *
     * @var array<string, string>
     */
    private const RENAME_MAP = [
        'Filament\\Resources\\Form'  => 'Filament\\Forms\\Form',
        'Filament\\Resources\\Table' => 'Filament\\Tables\\Table',
    ];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            '[filament_v3_form_table_namespace] Rename Filament\Resources\Form and Filament\Resources\Table to new V3 namespaces (Filament V2 → V3).',
            [
                new CodeSample(
                    <<<'CODE_BEFORE'
use Filament\Resources\Form;
use Filament\Resources\Table;
use Filament\Resources\Resource;

class PostResource extends Resource
{
    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([]);
    }
}
CODE_BEFORE,
                    <<<'CODE_AFTER'
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Resources\Resource;

class PostResource extends Resource
{
    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([]);
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
        return [Name::class];
    }

    /**
     * @param Name $node
     */
    public function refactor(Node $node): ?Node
    {
        $fullName = $node->toString();

        $newName = self::RENAME_MAP[$fullName] ?? null;
        if ($newName === null) {
            return null;
        }

        $newParts = explode('\\', $newName);

        if ($node instanceof FullyQualified) {
            return new FullyQualified($newParts, $node->getAttributes());
        }

        return new Name($newParts, $node->getAttributes());
    }
}
