<?php

declare(strict_types=1);

namespace App\Commands;

use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Output\OutputInterface;

final class TokenRedactor
{
    private const REDACTED = '[REDACTED]';

    /** @var list<string> */
    private array $tokens = [];

    /**
     * Register a token to redact. Empty strings are ignored.
     */
    public function addToken(string $token): void
    {
        if ($token === '') {
            return;
        }

        $this->tokens[] = $token;
    }

    /**
     * Replace all registered tokens in $text with [REDACTED].
     */
    public function redact(string $text): string
    {
        if ($this->tokens === []) {
            return $text;
        }

        return str_replace($this->tokens, self::REDACTED, $text);
    }

    /**
     * Wrap an OutputInterface to auto-redact all writes.
     */
    public function wrapOutput(OutputInterface $output): OutputInterface
    {
        $redactor = $this;

        return new class ($output, $redactor) extends Output {
            public function __construct(
                private readonly OutputInterface $inner,
                private readonly TokenRedactor $redactor,
            ) {
                parent::__construct(
                    $inner->getVerbosity(),
                    $inner->isDecorated(),
                    $inner->getFormatter(),
                );
            }

            protected function doWrite(string $message, bool $newline): void
            {
                $safe = $this->redactor->redact($message);
                if ($newline) {
                    $this->inner->writeln($safe);
                } else {
                    $this->inner->write($safe);
                }
            }
        };
    }
}
