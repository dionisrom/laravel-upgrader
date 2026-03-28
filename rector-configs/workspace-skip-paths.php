<?php

declare(strict_types=1);

/**
 * @return list<string>
 */
function upgraderWorkspaceSkipPaths(string $workspacePath = '/workspace'): array
{
    $normalizedWorkspacePath = rtrim(str_replace('\\', '/', $workspacePath), '/');
    $skipPaths = [];

    foreach (['vendor', 'storage', 'bootstrap/cache', 'node_modules'] as $relativePath) {
        $path = $normalizedWorkspacePath . '/' . $relativePath;

        if (is_dir($path)) {
            $skipPaths[] = $path;
        }
    }

    return $skipPaths;
}