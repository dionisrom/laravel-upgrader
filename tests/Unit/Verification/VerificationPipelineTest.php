<?php

declare(strict_types=1);

namespace Tests\Unit\Verification;

use AppContainer\Verification\VerificationContext;
use AppContainer\Verification\VerificationIssue;
use AppContainer\Verification\VerificationPipeline;
use AppContainer\Verification\VerifierInterface;
use AppContainer\Verification\VerifierResult;
use PHPUnit\Framework\TestCase;

final class VerificationPipelineTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pipeline_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    public function testRunsAllVerifiersWhenAllPass(): void
    {
        $called = [];

        $v1 = $this->stubVerifier('V1', true, $called);
        $v2 = $this->stubVerifier('V2', true, $called);
        $v3 = $this->stubVerifier('V3', true, $called);

        $pipeline = new VerificationPipeline([$v1, $v2, $v3]);
        $ctx      = new VerificationContext(workspacePath: $this->tmpDir);
        $results  = $pipeline->run($this->tmpDir, $ctx);

        self::assertCount(3, $results);
        self::assertSame(['V1', 'V2', 'V3'], $called);
        self::assertTrue($pipeline->passed($results));
    }

    public function testStopsAfterFirstFailure(): void
    {
        $called = [];

        $v1 = $this->stubVerifier('V1', true, $called);
        $v2 = $this->stubVerifier('V2', false, $called);   // fails
        $v3 = $this->stubVerifier('V3', true, $called);    // should NOT run

        $pipeline = new VerificationPipeline([$v1, $v2, $v3]);
        $ctx      = new VerificationContext(workspacePath: $this->tmpDir);
        $results  = $pipeline->run($this->tmpDir, $ctx);

        self::assertCount(2, $results);
        self::assertSame(['V1', 'V2'], $called);
        self::assertFalse($pipeline->passed($results));
    }

    public function testStopsImmediatelyWhenFirstVerifierFails(): void
    {
        $called = [];

        $v1 = $this->stubVerifier('V1', false, $called);  // fails immediately
        $v2 = $this->stubVerifier('V2', true, $called);   // should NOT run

        $pipeline = new VerificationPipeline([$v1, $v2]);
        $ctx      = new VerificationContext(workspacePath: $this->tmpDir);
        $results  = $pipeline->run($this->tmpDir, $ctx);

        self::assertCount(1, $results);
        self::assertSame(['V1'], $called);
        self::assertFalse($pipeline->passed($results));
    }

    public function testPassedReturnsFalseForFailedResult(): void
    {
        $pipeline = new VerificationPipeline([]);
        $results  = [
            new VerifierResult(
                passed:          false,
                verifierName:    'FailingVerifier',
                issueCount:      1,
                issues:          [new VerificationIssue('', 0, 'oops', 'error')],
                durationSeconds: 0.01,
            ),
        ];

        self::assertFalse($pipeline->passed($results));
    }

    public function testPassedReturnsTrueForEmptyResults(): void
    {
        $pipeline = new VerificationPipeline([]);

        self::assertTrue($pipeline->passed([]));
    }

    public function testPassedReturnsTrueWhenAllPass(): void
    {
        $pipeline = new VerificationPipeline([]);
        $results  = [
            new VerifierResult(
                passed:          true,
                verifierName:    'V1',
                issueCount:      0,
                issues:          [],
                durationSeconds: 0.01,
            ),
            new VerifierResult(
                passed:          true,
                verifierName:    'V2',
                issueCount:      0,
                issues:          [],
                durationSeconds: 0.02,
            ),
        ];

        self::assertTrue($pipeline->passed($results));
    }

    public function testResultsIncludeFailingVerifierResult(): void
    {
        $issue = new VerificationIssue('file.php', 5, 'Syntax error', 'error');
        $v1    = $this->stubVerifierWithIssues('V1', false, [$issue]);

        $pipeline = new VerificationPipeline([$v1]);
        $ctx      = new VerificationContext(workspacePath: $this->tmpDir);
        $results  = $pipeline->run($this->tmpDir, $ctx);

        self::assertCount(1, $results);
        self::assertFalse($results[0]->passed);
        self::assertSame(1, $results[0]->issueCount);
        self::assertSame('Syntax error', $results[0]->issues[0]->message);
    }

    public function testEmitsEventViaEmitterAfterEachVerifier(): void
    {
        // EventEmitter is final — use a real instance with an in-memory stream
        $stream  = fopen('php://memory', 'rw');
        self::assertIsResource($stream);

        $emitter = new \AppContainer\EventEmitter('test-hop', $stream);

        $called = [];
        $v1     = $this->stubVerifier('V1', true, $called);
        $v2     = $this->stubVerifier('V2', true, $called);

        $pipeline = new VerificationPipeline([$v1, $v2], $emitter);
        $ctx      = new VerificationContext(workspacePath: $this->tmpDir);
        $pipeline->run($this->tmpDir, $ctx);

        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);

        self::assertIsString($output);
        $lines = array_filter(explode("\n", trim($output)));
        self::assertCount(2, $lines);

        $event1 = json_decode((string) array_values($lines)[0], true);
        $event2 = json_decode((string) array_values($lines)[1], true);

        self::assertSame('verification_result', $event1['event']);
        self::assertSame('V1', $event1['verifier']);
        self::assertSame('V2', $event2['verifier']);
    }

    /**
     * @param list<string> $called
     */
    private function stubVerifier(string $name, bool $passes, array &$called): VerifierInterface
    {
        return new class ($name, $passes, $called) implements VerifierInterface {
            /** @param list<string> $called */
            public function __construct(
                private readonly string $name,
                private readonly bool   $passes,
                private array          &$called,
            ) {}

            public function verify(string $workspacePath, VerificationContext $ctx): VerifierResult
            {
                $this->called[] = $this->name;

                return new VerifierResult(
                    passed:          $this->passes,
                    verifierName:    $this->name,
                    issueCount:      0,
                    issues:          [],
                    durationSeconds: 0.001,
                );
            }
        };
    }

    /**
     * @param list<VerificationIssue> $issues
     */
    private function stubVerifierWithIssues(string $name, bool $passes, array $issues): VerifierInterface
    {
        return new class ($name, $passes, $issues) implements VerifierInterface {
            /** @param list<VerificationIssue> $issues */
            public function __construct(
                private readonly string $name,
                private readonly bool   $passes,
                private readonly array  $issues,
            ) {}

            public function verify(string $workspacePath, VerificationContext $ctx): VerifierResult
            {
                return new VerifierResult(
                    passed:          $this->passes,
                    verifierName:    $this->name,
                    issueCount:      count($this->issues),
                    issues:          $this->issues,
                    durationSeconds: 0.001,
                );
            }
        };
    }
}
