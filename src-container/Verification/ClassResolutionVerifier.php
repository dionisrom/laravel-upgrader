<?php

declare(strict_types=1);

namespace AppContainer\Verification;

final class ClassResolutionVerifier implements VerifierInterface
{
    public function verify(string $workspacePath, VerificationContext $ctx): VerifierResult
    {
        $start  = microtime(true);
        $appDir = $workspacePath . '/app';

        if (!is_dir($appDir)) {
            return new VerifierResult(
                passed:          true,
                verifierName:    'ClassResolutionVerifier',
                issueCount:      0,
                issues:          [],
                durationSeconds: microtime(true) - $start,
            );
        }

        $files  = $this->findPhpFiles($appDir);
        $issues = [];

        foreach ($files as $file) {
            $content = file_get_contents($file);

            if ($content === false) {
                continue;
            }

            $classes = $this->extractUseStatements($content);

            foreach ($classes as $fqcn) {
                if (
                    !class_exists($fqcn, true)
                    && !interface_exists($fqcn, true)
                    && !trait_exists($fqcn, true)
                ) {
                    $issues[] = new VerificationIssue(
                        file:     $file,
                        line:     0,
                        message:  "Cannot resolve class: {$fqcn}",
                        severity: 'error',
                    );
                }
            }
        }

        return new VerifierResult(
            passed:          count($issues) === 0,
            verifierName:    'ClassResolutionVerifier',
            issueCount:      count($issues),
            issues:          $issues,
            durationSeconds: microtime(true) - $start,
        );
    }

    /**
     * Extract fully-qualified class names from `use` statements starting with App\.
     *
     * @return list<string>
     */
    private function extractUseStatements(string $content): array
    {
        $classes = [];

        if (preg_match_all('/^use\s+(App\\\\[A-Za-z0-9_\\\\]+);/m', $content, $matches)) {
            foreach ($matches[1] as $fqcn) {
                $classes[] = $fqcn;
            }
        }

        return $classes;
    }

    /**
     * @return list<string>
     */
    private function findPhpFiles(string $dir): array
    {
        $files    = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile() && $fileInfo->getExtension() === 'php') {
                $files[] = $fileInfo->getPathname();
            }
        }

        return $files;
    }
}
