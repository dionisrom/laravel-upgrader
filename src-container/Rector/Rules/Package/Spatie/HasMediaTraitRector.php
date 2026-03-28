<?php

declare(strict_types=1);

namespace AppContainer\Rector\Rules\Package\Spatie;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * spatie/laravel-medialibrary V9 → V10: fix renamed HasMediaTrait namespace.
 *
 * In spatie/laravel-medialibrary V10 the `HasMedia` interface and
 * `HasMediaTrait` trait were moved and renamed:
 *
 *   Spatie\MediaLibrary\HasMedia\HasMedia      → Spatie\MediaLibrary\HasMedia
 *   Spatie\MediaLibrary\HasMedia\HasMediaTrait → Spatie\MediaLibrary\InteractsWithMedia
 *
 * This rule updates `use` statement names (and direct FQCN references in code).
 *
 * @see \Tests\Unit\Rector\Rules\Package\Spatie\HasMediaTraitRectorTest
 */
final class HasMediaTraitRector extends AbstractRector
{
    /**
     * Old FQCN → new FQCN mapping.
     *
     * @var array<string, string>
     */
    private const RENAME_MAP = [
        'Spatie\\MediaLibrary\\HasMedia\\HasMedia'      => 'Spatie\\MediaLibrary\\HasMedia',
        'Spatie\\MediaLibrary\\HasMedia\\HasMediaTrait' => 'Spatie\\MediaLibrary\\InteractsWithMedia',
    ];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            '[spatie_medialibrary_v10_has_media_trait] Update HasMedia/HasMediaTrait namespaces for spatie/laravel-medialibrary V10 (V9 → V10).',
            [
                new CodeSample(
                    <<<'CODE_BEFORE'
use Spatie\MediaLibrary\HasMedia\HasMedia;
use Spatie\MediaLibrary\HasMedia\HasMediaTrait;

class Post implements HasMedia
{
    use HasMediaTrait;
}
CODE_BEFORE,
                    <<<'CODE_AFTER'
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Post implements HasMedia
{
    use InteractsWithMedia;
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

        // Preserve the node sub-type (FullyQualified vs plain Name).
        if ($node instanceof FullyQualified) {
            return new FullyQualified($newParts, $node->getAttributes());
        }

        return new Name($newParts, $node->getAttributes());
    }
}
