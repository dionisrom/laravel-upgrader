<?php

declare(strict_types=1);

namespace App\Report\Renderer;

/**
 * Renders a collapsible file tree sidebar using native HTML <details>/<summary>.
 *
 * No JavaScript is required for expand/collapse — it is handled by the browser's
 * native <details> behaviour.
 */
final class FileTreeRenderer
{
    /**
     * Render the file tree sidebar HTML.
     *
     * @param list<array{file: string, changeType: string}> $files
     */
    public function render(array $files): string
    {
        if ($files === []) {
            return '<p class="no-files">No changed files.</p>';
        }

        $tree = $this->buildTree($files);
        return $this->renderNode($tree);
    }

    // -----------------------------------------------------------------------
    // Tree construction
    // -----------------------------------------------------------------------

    /**
     * Build a nested tree structure from a flat file list.
     *
     * Each node is either:
     *   - A file leaf:  ['__type' => 'file', '__path' => ..., '__changeType' => ...]
     *   - A dir node:   ['__type' => 'dir', ...children keyed by name...]
     *
     * @param list<array{file: string, changeType: string}> $files
     * @return array<string, mixed>
     */
    private function buildTree(array $files): array
    {
        $tree = ['__type' => 'dir'];

        foreach ($files as $fileInfo) {
            $parts = array_values(array_filter(explode('/', $fileInfo['file']), static fn (string $p): bool => $p !== ''));
            if ($parts === []) {
                continue;
            }
            $tree = $this->insertIntoTree($tree, $parts, $fileInfo['file'], $fileInfo['changeType']);
        }

        return $tree;
    }

    /**
     * Recursively insert a file path into the tree.
     *
     * @param array<string, mixed> $tree
     * @param non-empty-list<string> $parts
     * @return array<string, mixed>
     */
    private function insertIntoTree(array $tree, array $parts, string $fullPath, string $changeType): array
    {
        $head = array_shift($parts);

        if ($parts === []) {
            // Leaf node (file)
            $tree[$head] = [
                '__type'       => 'file',
                '__path'       => $fullPath,
                '__changeType' => $changeType,
            ];
            return $tree;
        }

        // Directory node — recurse
        $child = isset($tree[$head]) && is_array($tree[$head])
            ? (array) $tree[$head]
            : ['__type' => 'dir'];

        /** @var non-empty-list<string> $parts */
        $tree[$head] = $this->insertIntoTree($child, $parts, $fullPath, $changeType);
        return $tree;
    }

    // -----------------------------------------------------------------------
    // HTML rendering
    // -----------------------------------------------------------------------

    /**
     * Render a tree node (directory or file) as HTML.
     *
     * @param array<string, mixed> $node
     */
    private function renderNode(array $node): string
    {
        $html     = '';
        $children = $this->sortedChildren($node);

        foreach ($children as $name => $child) {
            if (!is_array($child)) {
                continue;
            }

            $type = (string) ($child['__type'] ?? 'dir');

            if ($type === 'file') {
                $html .= $this->renderFile($name, $child);
            } else {
                $html .= $this->renderDir($name, $child);
            }
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function sortedChildren(array $node): array
    {
        $dirs  = [];
        $files = [];

        foreach ($node as $key => $child) {
            if (str_starts_with((string) $key, '__') || !is_array($child)) {
                continue;
            }
            $childType = (string) (((array) $child)['__type'] ?? 'dir');
            if ($childType === 'file') {
                $files[$key] = $child;
            } else {
                $dirs[$key] = $child;
            }
        }

        ksort($dirs);
        ksort($files);

        return array_merge($dirs, $files);
    }

    /**
     * @param array<string, mixed> $fileNode
     */
    private function renderFile(string $name, array $fileNode): string
    {
        $path       = htmlspecialchars((string) ($fileNode['__path'] ?? $name), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $changeType = htmlspecialchars((string) ($fileNode['__changeType'] ?? 'auto'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $nameEsc    = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $icon       = $this->changeTypeIcon((string) ($fileNode['__changeType'] ?? 'auto'));
        $label      = htmlspecialchars(ucfirst((string) ($fileNode['__changeType'] ?? 'auto')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
        <div class="file-entry {$changeType}"
             data-file="{$path}"
             data-change-type="{$changeType}"
             role="button"
             tabindex="0"
             aria-label="View diff for {$nameEsc} ({$label})">
          <span class="file-icon" aria-hidden="true">{$icon}</span>
          <span class="file-name">{$nameEsc}</span>
          <span class="change-badge badge-{$changeType}" aria-label="{$label}">{$label}</span>
        </div>
        HTML;
    }

    /**
     * @param array<string, mixed> $dirNode
     */
    private function renderDir(string $name, array $dirNode): string
    {
        $nameEsc  = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $children = $this->renderNode($dirNode);

        return <<<HTML
        <details open class="dir-node">
          <summary class="dir-entry">
            <span class="dir-icon" aria-hidden="true">📁</span>
            <span class="dir-name">{$nameEsc}/</span>
          </summary>
          <div class="dir-children">
            {$children}
          </div>
        </details>
        HTML;
    }

    private function changeTypeIcon(string $changeType): string
    {
        return match ($changeType) {
            'auto'   => '🟢',
            'review' => '🟡',
            'manual' => '🔴',
            default  => '📄',
        };
    }
}
