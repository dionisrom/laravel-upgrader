<?php

declare(strict_types=1);

namespace AppContainer\Rector;

final class RectorExecutionException extends \RuntimeException
{
    public static function fromProcessFailure(int $exitCode, string $stderr): self
    {
        return new self(
            sprintf('Rector process exited with code %d: %s', $exitCode, $stderr),
            $exitCode,
        );
    }

    public static function fromInvalidJson(string $json, \JsonException $e): self
    {
        $preview = substr($json, 0, 200);

        return new self(
            sprintf('Failed to parse Rector JSON output: %s (preview: %s)', $e->getMessage(), $preview),
            0,
            $e,
        );
    }
}
