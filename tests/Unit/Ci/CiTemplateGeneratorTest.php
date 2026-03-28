<?php

declare(strict_types=1);

namespace Tests\Unit\Ci;

use App\Ci\CiTemplateGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CiTemplateGeneratorTest extends TestCase
{
    private function tester(): CommandTester
    {
        return new CommandTester(new CiTemplateGenerator());
    }

    // ── Platform output ────────────────────────────────────────────────────

    public function testGithubPlatformOutputsYaml(): void
    {
        $tester = $this->tester();
        $exitCode = $tester->execute(['--platform' => 'github']);

        self::assertSame(Command::SUCCESS, $exitCode);
        $output = $tester->getDisplay();
        self::assertStringContainsString('workflow_dispatch', $output);
        self::assertStringContainsString('actions/checkout@v4', $output);
        self::assertStringContainsString('upload-artifact', $output);
        self::assertStringContainsString('--network=none', $output);
    }

    public function testGitlabPlatformOutputsYaml(): void
    {
        $tester = $this->tester();
        $exitCode = $tester->execute(['--platform' => 'gitlab']);

        self::assertSame(Command::SUCCESS, $exitCode);
        $output = $tester->getDisplay();
        self::assertStringContainsString('stages:', $output);
        self::assertStringContainsString('docker:24-dind', $output);
        self::assertStringContainsString('artifacts:', $output);
        self::assertStringContainsString('--network=none', $output);
    }

    public function testBitbucketPlatformOutputsYaml(): void
    {
        $tester = $this->tester();
        $exitCode = $tester->execute(['--platform' => 'bitbucket']);

        self::assertSame(Command::SUCCESS, $exitCode);
        $output = $tester->getDisplay();
        self::assertStringContainsString('pipelines:', $output);
        self::assertStringContainsString('definitions:', $output);
        self::assertStringContainsString('--network=none', $output);
        self::assertStringContainsString('laravel-upgrade-dry-run', $output);
        self::assertStringContainsString('laravel-upgrade-auto', $output);
    }

    // ── Version interpolation ──────────────────────────────────────────────

    public function testFromVersionAppearsInGithubOutput(): void
    {
        $tester = $this->tester();
        $tester->execute(['--platform' => 'github', '--from' => '10', '--to' => '11']);

        $output = $tester->getDisplay();
        self::assertStringContainsString("default: '10'", $output);
        self::assertStringContainsString("default: '11'", $output);
    }

    public function testFromVersionAppearsInGitlabOutput(): void
    {
        $tester = $this->tester();
        $tester->execute(['--platform' => 'gitlab', '--from' => '10', '--to' => '11']);

        $output = $tester->getDisplay();
        self::assertStringContainsString('FROM_VERSION: "10"', $output);
        self::assertStringContainsString('TO_VERSION: "11"', $output);
    }

    public function testFromVersionAppearsInBitbucketOutput(): void
    {
        $tester = $this->tester();
        $tester->execute(['--platform' => 'bitbucket', '--from' => '10', '--to' => '11']);

        $output = $tester->getDisplay();
        self::assertStringContainsString("default: '10'", $output);
        self::assertStringContainsString("default: '11'", $output);
    }

    // ── Mode switching ─────────────────────────────────────────────────────

    public function testDryRunModeIsEmbeddedInGithubOutput(): void
    {
        $tester = $this->tester();
        $tester->execute(['--platform' => 'github', '--mode' => 'dry-run']);

        $output = $tester->getDisplay();
        self::assertStringContainsString("default: 'dry-run'", $output);
    }

    public function testAutoUpgradeModeIsEmbeddedInGithubOutput(): void
    {
        $tester = $this->tester();
        $tester->execute(['--platform' => 'github', '--mode' => 'auto-upgrade']);

        $output = $tester->getDisplay();
        self::assertStringContainsString("default: 'auto-upgrade'", $output);
    }

    public function testDryRunModeIsEmbeddedInGitlabOutput(): void
    {
        $tester = $this->tester();
        $tester->execute(['--platform' => 'gitlab', '--mode' => 'dry-run']);

        $output = $tester->getDisplay();
        self::assertStringContainsString('UPGRADE_MODE: "dry-run"', $output);
    }

    public function testAutoUpgradeModeIsEmbeddedInGitlabOutput(): void
    {
        $tester = $this->tester();
        $tester->execute(['--platform' => 'gitlab', '--mode' => 'auto-upgrade']);

        $output = $tester->getDisplay();
        self::assertStringContainsString('UPGRADE_MODE: "auto-upgrade"', $output);
    }

    // ── Image substitution ─────────────────────────────────────────────────

    public function testCustomImageAppearsInGithubOutput(): void
    {
        $tester = $this->tester();
        $tester->execute(['--platform' => 'github', '--image' => 'registry.example.com/upgrader:v2']);

        $output = $tester->getDisplay();
        self::assertStringContainsString('registry.example.com/upgrader:v2', $output);
    }

    public function testCustomImageAppearsInGitlabOutput(): void
    {
        $tester = $this->tester();
        $tester->execute(['--platform' => 'gitlab', '--image' => 'registry.example.com/upgrader:v2']);

        $output = $tester->getDisplay();
        self::assertStringContainsString('registry.example.com/upgrader:v2', $output);
    }

    public function testCustomImageAppearsInBitbucketOutput(): void
    {
        $tester = $this->tester();
        $tester->execute(['--platform' => 'bitbucket', '--image' => 'registry.example.com/upgrader:v2']);

        $output = $tester->getDisplay();
        self::assertStringContainsString('registry.example.com/upgrader:v2', $output);
    }

    // ── Validation ─────────────────────────────────────────────────────────

    public function testMissingPlatformReturnsInvalid(): void
    {
        $tester = $this->tester();
        $exitCode = $tester->execute([]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('--platform is required', $tester->getDisplay());
    }

    public function testUnknownPlatformReturnsInvalid(): void
    {
        $tester = $this->tester();
        $exitCode = $tester->execute(['--platform' => 'jenkins']);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('Unknown platform', $tester->getDisplay());
    }

    public function testUnknownModeReturnsInvalid(): void
    {
        $tester = $this->tester();
        $exitCode = $tester->execute(['--platform' => 'github', '--mode' => 'full-yolo']);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('Unknown mode', $tester->getDisplay());
    }

    // ── Default values ─────────────────────────────────────────────────────

    public function testDefaultsAreLaravel8to9(): void
    {
        $tester = $this->tester();
        $tester->execute(['--platform' => 'github']);

        $output = $tester->getDisplay();
        self::assertStringContainsString("default: '8'", $output);
        self::assertStringContainsString("default: '9'", $output);
    }

    public function testDefaultModeIsDryRun(): void
    {
        $tester = $this->tester();
        $tester->execute(['--platform' => 'github']);

        $output = $tester->getDisplay();
        self::assertStringContainsString("default: 'dry-run'", $output);
    }

    // ── Template completeness ──────────────────────────────────────────────

    public function testAllTemplatesContainNetworkNoneFlag(): void
    {
        $tester = $this->tester();

        foreach (['github', 'gitlab', 'bitbucket'] as $platform) {
            $tester->execute(['--platform' => $platform]);
            self::assertStringContainsString(
                '--network=none',
                $tester->getDisplay(),
                "Platform '{$platform}' template is missing --network=none",
            );
        }
    }

    public function testAllTemplatesContainUpgraderTokenReference(): void
    {
        $tester = $this->tester();

        foreach (['github', 'gitlab', 'bitbucket'] as $platform) {
            $tester->execute(['--platform' => $platform]);
            self::assertStringContainsString(
                'UPGRADER_TOKEN',
                $tester->getDisplay(),
                "Platform '{$platform}' template is missing UPGRADER_TOKEN reference",
            );
        }
    }

    public function testGithubTemplateContainsArtifactUploadStep(): void
    {
        $tester = $this->tester();
        $tester->execute(['--platform' => 'github']);

        self::assertStringContainsString('upload-artifact', $tester->getDisplay());
    }

    public function testGitlabTemplateContainsArtifactDefinition(): void
    {
        $tester = $this->tester();
        $tester->execute(['--platform' => 'gitlab']);

        $output = $tester->getDisplay();
        self::assertStringContainsString('artifacts:', $output);
        self::assertStringContainsString('expire_in:', $output);
    }

    public function testBitbucketTemplateContainsArtifacts(): void
    {
        $tester = $this->tester();
        $tester->execute(['--platform' => 'bitbucket']);

        $output = $tester->getDisplay();
        self::assertStringContainsString('artifacts:', $output);
        self::assertStringContainsString('.upgrader/reports/**', $output);
    }

    // ── PR comment in dry-run mode ─────────────────────────────────────────

    public function testGithubTemplateContainsPrCommentStep(): void
    {
        $tester = $this->tester();
        $tester->execute(['--platform' => 'github']);

        $output = $tester->getDisplay();
        self::assertStringContainsString('gh pr comment', $output);
        self::assertStringContainsString('summary.md', $output);
    }

    public function testGitlabTemplateContainsMrNoteApiCall(): void
    {
        $tester = $this->tester();
        $tester->execute(['--platform' => 'gitlab']);

        $output = $tester->getDisplay();
        self::assertStringContainsString('merge_requests/${CI_MERGE_REQUEST_IID}/notes', $output);
    }

    public function testBitbucketTemplateContainsPrCommentApiCall(): void
    {
        $tester = $this->tester();
        $tester->execute(['--platform' => 'bitbucket']);

        $output = $tester->getDisplay();
        self::assertStringContainsString('pullrequests/${BITBUCKET_PR_ID}/comments', $output);
        self::assertStringContainsString('post-pr-comment', $output);
    }

    // ── Static template file existence and content ───────────────────────

    public function testStaticTemplateFilesExist(): void
    {
        $base = dirname(__DIR__, 3) . '/templates/ci';
        self::assertFileExists($base . '/github/github-actions.yml');
        self::assertFileExists($base . '/gitlab/gitlab-ci.yml');
        self::assertFileExists($base . '/bitbucket/bitbucket-pipelines.yml');
    }

    public function testStaticTemplateFilesContainPlaceholders(): void
    {
        $base = dirname(__DIR__, 3) . '/templates/ci';

        $github = file_get_contents($base . '/github/github-actions.yml');
        self::assertStringContainsString('{{FROM_VERSION}}', $github);
        self::assertStringContainsString('{{IMAGE}}', $github);

        $gitlab = file_get_contents($base . '/gitlab/gitlab-ci.yml');
        self::assertStringContainsString('{{FROM_VERSION}}', $gitlab);
        self::assertStringContainsString('{{IMAGE}}', $gitlab);

        $bitbucket = file_get_contents($base . '/bitbucket/bitbucket-pipelines.yml');
        self::assertStringContainsString('{{FROM_VERSION}}', $bitbucket);
        self::assertStringContainsString('{{IMAGE}}', $bitbucket);
    }

    public function testNoPlaceholdersRemainingAfterRender(): void
    {
        $tester = $this->tester();

        foreach (['github', 'gitlab', 'bitbucket'] as $platform) {
            $tester->execute(['--platform' => $platform, '--from' => '10', '--to' => '11']);
            $output = $tester->getDisplay();
            self::assertStringNotContainsString(
                '{{FROM_VERSION}}',
                $output,
                "Platform '{$platform}' has unsubstituted {{FROM_VERSION}} placeholder",
            );
            self::assertStringNotContainsString(
                '{{TO_VERSION}}',
                $output,
                "Platform '{$platform}' has unsubstituted {{TO_VERSION}} placeholder",
            );
            self::assertStringNotContainsString(
                '{{DEFAULT_MODE}}',
                $output,
                "Platform '{$platform}' has unsubstituted {{DEFAULT_MODE}} placeholder",
            );
            self::assertStringNotContainsString(
                '{{IMAGE}}',
                $output,
                "Platform '{$platform}' has unsubstituted {{IMAGE}} placeholder",
            );
        }
    }
}
