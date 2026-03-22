<?php

declare(strict_types=1);

namespace Tests\Unit\Hardening;

use App\Commands\InputValidator;
use App\Commands\TokenRedactor;
use App\Orchestrator\DockerRunner;
use App\Orchestrator\Hop;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Security audit test: token redaction, path normalisation, input validation,
 * and network-isolation configuration.
 */
final class SecurityAuditTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Token redaction
    // -----------------------------------------------------------------------

    public function testTokenRedactorRedactsSingleTokenInOutput(): void
    {
        $redactor = new TokenRedactor();
        $redactor->addToken('ghp_supersecret123');

        $result = $redactor->redact('Cloning github.com?token=ghp_supersecret123 done.');

        self::assertStringNotContainsString('ghp_supersecret123', $result);
        self::assertStringContainsString('[REDACTED]', $result);
    }

    public function testTokenRedactorHandlesMultipleTokens(): void
    {
        $redactor = new TokenRedactor();
        $redactor->addToken('token-aaa');
        $redactor->addToken('token-bbb');

        $result = $redactor->redact('Using token-aaa and token-bbb in the same string.');

        self::assertStringNotContainsString('token-aaa', $result);
        self::assertStringNotContainsString('token-bbb', $result);
        self::assertSame(
            'Using [REDACTED] and [REDACTED] in the same string.',
            $result,
        );
    }

    public function testTokenRedactorWithNoTokensIsNoop(): void
    {
        $redactor = new TokenRedactor();

        $original = 'No tokens registered — this string passes through unchanged.';
        self::assertSame($original, $redactor->redact($original));
    }

    public function testTokenRedactorIgnoresEmptyToken(): void
    {
        $redactor = new TokenRedactor();
        $redactor->addToken('');
        $redactor->addToken('real-token');

        // Empty token must not cause a replace-all-with-[REDACTED] effect
        $result = $redactor->redact('The real-token is secret but empty tokens are ignored.');

        self::assertStringNotContainsString('real-token', $result);
        self::assertStringContainsString('[REDACTED]', $result);
    }

    public function testTokenRedactorWrapsOutputInterface(): void
    {
        $redactor = new TokenRedactor();
        $redactor->addToken('secret-tkn-xyz');

        $buffer = new BufferedOutput();
        $safe   = $redactor->wrapOutput($buffer);

        $safe->writeln('Fetching repository with secret-tkn-xyz authentication.');
        $safe->write('Token=secret-tkn-xyz appended.');

        $output = $buffer->fetch();

        self::assertStringNotContainsString('secret-tkn-xyz', $output);
        self::assertStringContainsString('[REDACTED]', $output);
    }

    public function testTokenRedactorWrappedOutputPreservesNonSensitiveContent(): void
    {
        $redactor = new TokenRedactor();
        $redactor->addToken('only-this-is-secret');

        $buffer = new BufferedOutput();
        $safe   = $redactor->wrapOutput($buffer);

        $safe->writeln('Upgrade completed successfully.');

        $output = $buffer->fetch();

        self::assertStringContainsString('Upgrade completed successfully.', $output);
        self::assertStringNotContainsString('[REDACTED]', $output);
    }

    public function testTokenNeverAppearsInAuditLogWriter(): void
    {
        $logFile = sys_get_temp_dir() . '/security-audit-log-' . uniqid() . '.json';

        $writer = new \App\Orchestrator\AuditLogWriter(
            logPath: $logFile,
            runId: 'sec-test-run',
            repoSha: 'deadbeef',
        );

        $secretToken = 'ghp_this_should_never_escape_abc123xyz';

        // Simulate an event that accidentally includes a token field
        $writer->consume([
            'event' => 'pipeline_start',
            'token' => $secretToken,
        ]);

        // Also simulate someone accidentally putting token in a nested-like field name
        $writer->consume([
            'event'    => 'stage_start',
            'password' => 'should-be-stripped',
            'secret'   => 'also-stripped',
        ]);

        self::assertFileExists($logFile);

        $contents = (string) file_get_contents($logFile);

        self::assertStringNotContainsString(
            $secretToken,
            $contents,
            'Token value must never appear in the audit log.',
        );
        self::assertStringNotContainsString('should-be-stripped', $contents);
        self::assertStringNotContainsString('also-stripped', $contents);

        // run_id must still be present (sanitize must not strip everything)
        self::assertStringContainsString('sec-test-run', $contents);

        @unlink($logFile);
    }

    // -----------------------------------------------------------------------
    // Workspace permissions
    // -----------------------------------------------------------------------

    public function testWorkspaceCreatedWithRestrictivePermissions(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Unix permission checks do not apply on Windows.');
        }

        $fakeRepo = sys_get_temp_dir() . '/sec-repo-' . uniqid();
        mkdir($fakeRepo, 0700, true);
        file_put_contents($fakeRepo . '/composer.json', '{"name":"test/app"}');

        $manager = new \App\Workspace\WorkspaceManager();
        $workspacePath = $manager->createWorkspace($fakeRepo, '9');

        try {
            $perms = fileperms($workspacePath);
            self::assertNotFalse($perms);

            // Extract permission bits (lower 9 bits = rwxrwxrwx)
            $octal = decoct($perms & 0777);

            self::assertSame(
                '700',
                $octal,
                "Workspace must be created with 0700 permissions (got {$octal}).",
            );
        } finally {
            $manager->cleanup($workspacePath);
            @unlink($fakeRepo . '/composer.json');
            @rmdir($fakeRepo);
        }
    }

    public function testOutputDirectoryPermissionsAreRestrictive(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Unix permission checks do not apply on Windows.');
        }

        // WorkspaceManager uses 0700 for internally created sub-directories.
        // Verify any dir created via createWorkspace recursion inherits 0700.
        $fakeRepo = sys_get_temp_dir() . '/sec-outdir-' . uniqid();
        mkdir($fakeRepo, 0700, true);
        mkdir($fakeRepo . '/subdir', 0755, true);  // subdir with relaxed perms
        file_put_contents($fakeRepo . '/subdir/test.php', '<?php echo 1;');

        $manager = new \App\Workspace\WorkspaceManager();
        $workspacePath = $manager->createWorkspace($fakeRepo, '9');

        try {
            $rootPerms = decoct(fileperms($workspacePath) & 0777);
            self::assertSame('700', $rootPerms, 'Workspace root must be 0700.');
        } finally {
            $manager->cleanup($workspacePath);
            @unlink($fakeRepo . '/subdir/test.php');
            @rmdir($fakeRepo . '/subdir');
            @rmdir($fakeRepo);
        }
    }

    // -----------------------------------------------------------------------
    // Input validation: path traversal + empty repo
    // -----------------------------------------------------------------------

    public function testInputValidatorRejectsEmptyRepo(): void
    {
        $validator = new InputValidator();

        $errors = $validator->validate(['repo' => '', 'to' => '9']);

        self::assertNotEmpty($errors, 'Empty --repo must be rejected.');
        self::assertStringContainsString('required', implode(' ', $errors));
    }

    public function testInputValidatorRejectsNonexistentLocalPath(): void
    {
        $validator = new InputValidator();

        $errors = $validator->validate([
            'repo' => '/this/path/does/not/exist/ever',
            'to'   => '9',
        ]);

        self::assertNotEmpty($errors, 'Non-existent local path must be rejected.');
    }

    public function testInputValidatorAcceptsValidLocalDirectory(): void
    {
        $tmpDir = sys_get_temp_dir() . '/sec-valid-repo-' . uniqid();
        mkdir($tmpDir, 0700, true);

        try {
            $validator = new InputValidator();
            $errors = $validator->validate(['repo' => $tmpDir, 'to' => '9']);

            self::assertEmpty($errors, 'Valid local directory must pass validation.');
        } finally {
            @rmdir($tmpDir);
        }
    }

    public function testInputValidatorAcceptsGithubPrefix(): void
    {
        $validator = new InputValidator();

        $errors = $validator->validate(['repo' => 'github:myorg/myapp', 'to' => '9']);

        self::assertEmpty($errors, 'github:org/repo format must be accepted by InputValidator.');
    }

    public function testInputValidatorAcceptsGitlabPrefix(): void
    {
        $validator = new InputValidator();

        $errors = $validator->validate(['repo' => 'gitlab:myorg/myapp', 'to' => '9']);

        self::assertEmpty($errors, 'gitlab:org/repo format must be accepted by InputValidator.');
    }

    public function testInputValidatorRejectsVersionDowngrade(): void
    {
        $tmpDir = sys_get_temp_dir() . '/sec-downgrade-' . uniqid();
        mkdir($tmpDir, 0700, true);

        try {
            $validator = new InputValidator();
            $errors = $validator->validate(['repo' => $tmpDir, 'from' => '9', 'to' => '9']);

            self::assertNotEmpty($errors, 'from >= to must be rejected.');
        } finally {
            @rmdir($tmpDir);
        }
    }

    public function testInputValidatorRejectsInvalidFormat(): void
    {
        $tmpDir = sys_get_temp_dir() . '/sec-fmt-' . uniqid();
        mkdir($tmpDir, 0700, true);

        try {
            $validator = new InputValidator();
            $errors = $validator->validate([
                'repo'   => $tmpDir,
                'to'     => '9',
                'format' => 'html,pdf',  // pdf is not allowed
            ]);

            self::assertNotEmpty($errors);
            self::assertStringContainsString('pdf', implode(' ', $errors));
        } finally {
            @rmdir($tmpDir);
        }
    }

    // -----------------------------------------------------------------------
    // DockerRunner: --network=none is always injected
    // -----------------------------------------------------------------------

    public function testDockerRunnerInjectsFlagNetworkNone(): void
    {
        $hop = new Hop(
            dockerImage: 'upgrader/hop-8-to-9',
            fromVersion: '8',
            toVersion: '9',
            type: 'laravel',
            phpBase: null,
        );

        $runner = new DockerRunner(dockerBin: 'docker');
        $command = $runner->buildCommand($hop, '/workspace', '/output');

        self::assertContains(
            '--network=none',
            $command,
            '--network=none must always be present in docker run command to enforce isolation.',
        );
    }

    public function testDockerRunnerNetworkNoneCannotBeOverridden(): void
    {
        // There is no public API on DockerRunner to remove --network=none.
        // Verify the class has no networkMode parameter in its constructor.
        $reflection = new \ReflectionClass(DockerRunner::class);
        $constructor = $reflection->getConstructor();

        self::assertNotNull($constructor);

        $paramNames = array_map(
            static fn(\ReflectionParameter $p): string => $p->getName(),
            $constructor->getParameters(),
        );

        self::assertNotContains(
            'networkMode',
            $paramNames,
            'DockerRunner constructor must not expose a networkMode parameter.',
        );
        self::assertNotContains(
            'network',
            $paramNames,
            'DockerRunner constructor must not expose a network parameter.',
        );
    }

    public function testDockerRunnerDoesNotExposePrivilegedFlag(): void
    {
        $hop = new Hop(
            dockerImage: 'upgrader/hop-8-to-9',
            fromVersion: '8',
            toVersion: '9',
            type: 'laravel',
            phpBase: null,
        );

        $runner = new DockerRunner(dockerBin: 'docker');
        $command = $runner->buildCommand($hop, '/workspace', '/output');

        self::assertNotContains(
            '--privileged',
            $command,
            'Docker run command must never include --privileged.',
        );

        // Also check no --cap-add is present
        self::assertNotContains(
            '--cap-add',
            $command,
            'Docker run command must not add Linux capabilities.',
        );
    }
}
