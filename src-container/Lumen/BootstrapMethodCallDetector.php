<?php

declare(strict_types=1);

namespace AppContainer\Lumen;

final class BootstrapMethodCallDetector
{
    public function hasMethodCall(string $code, string $method): bool
    {
        return str_contains($this->stripComments($code), "->{$method}(");
    }

    private function stripComments(string $code): string
    {
        $tokens = token_get_all($code);
        $normalized = '';

        foreach ($tokens as $token) {
            if (!is_array($token)) {
                $normalized .= $token;
                continue;
            }

            [$tokenId, $text] = $token;

            if ($tokenId === T_COMMENT || $tokenId === T_DOC_COMMENT) {
                continue;
            }

            $normalized .= $text;
        }

        return $normalized;
    }
}