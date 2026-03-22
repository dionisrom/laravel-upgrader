<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use App\Commands\InputValidator;
use PHPUnit\Framework\TestCase;

final class InputValidatorTest extends TestCase
{
    private InputValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new InputValidator();
    }

    public function testValidOptionsPassValidation(): void
    {
        $errors = $this->validator->validate([
            'repo' => __DIR__, // existing directory
            'to'   => '9',
        ]);

        self::assertSame([], $errors);
    }

    public function testMissingRepoFailsValidation(): void
    {
        $errors = $this->validator->validate([
            'repo' => '',
            'to'   => '9',
        ]);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('--repo', $errors[0]);
    }

    public function testInvalidToVersionFailsValidation(): void
    {
        $errors = $this->validator->validate([
            'repo' => __DIR__,
            'to'   => '7',
        ]);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('Laravel 9', $errors[0]);
    }

    public function testFromGreaterThanToFailsValidation(): void
    {
        $errors = $this->validator->validate([
            'repo' => __DIR__,
            'from' => '10',
            'to'   => '9',
        ]);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('less than', $errors[0]);
    }

    public function testInvalidFormatFailsValidation(): void
    {
        $errors = $this->validator->validate([
            'repo'   => __DIR__,
            'to'     => '9',
            'format' => 'pdf',
        ]);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('pdf', $errors[0]);
    }

    public function testNonExistentLocalRepoFailsValidation(): void
    {
        $errors = $this->validator->validate([
            'repo' => '/this/path/does/not/exist/ever',
            'to'   => '9',
        ]);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('does not exist', $errors[0]);
    }
}
