<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use App\Commands\TokenRedactor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

final class TokenRedactorTest extends TestCase
{
    public function testRedactReplacesToken(): void
    {
        $redactor = new TokenRedactor();
        $redactor->addToken('secret-token');

        self::assertSame('Bearer [REDACTED]', $redactor->redact('Bearer secret-token'));
    }

    public function testRedactMultipleTokens(): void
    {
        $redactor = new TokenRedactor();
        $redactor->addToken('token-a');
        $redactor->addToken('token-b');

        self::assertSame('[REDACTED] and [REDACTED]', $redactor->redact('token-a and token-b'));
    }

    public function testRedactIgnoresEmptyToken(): void
    {
        $redactor = new TokenRedactor();
        $redactor->addToken('');
        $redactor->addToken('real-token');

        // Empty token should not corrupt all strings
        self::assertSame('[REDACTED]', $redactor->redact('real-token'));
    }

    public function testRedactDoesNothingWithNoTokens(): void
    {
        $redactor = new TokenRedactor();
        $text = 'no tokens registered, this should pass through unchanged';

        self::assertSame($text, $redactor->redact($text));
    }

    public function testWrapOutputRedactsWrites(): void
    {
        $redactor = new TokenRedactor();
        $redactor->addToken('my-secret');

        $inner  = new BufferedOutput();
        $wrapped = $redactor->wrapOutput($inner);

        $wrapped->writeln('token is my-secret here');

        self::assertStringContainsString('[REDACTED]', $inner->fetch());
    }
}
