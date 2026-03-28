<?php

declare(strict_types=1);

namespace App\Ci;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Outputs a ready-to-use CI/CD pipeline template for the Laravel Enterprise Upgrader.
 *
 * Usage:
 *   bin/upgrader ci:generate --platform=github --from=10 --to=11 --mode=dry-run > .github/workflows/laravel-upgrade.yml
 */
final class CiTemplateGenerator extends Command
{
    protected static string $defaultName = 'ci:generate';
    protected static string $defaultDescription = 'Output a CI/CD pipeline template for the Laravel Upgrader';

    private const PLATFORMS = ['github', 'gitlab', 'bitbucket'];
    private const MODES = ['dry-run', 'auto-upgrade'];
    private const DEFAULT_IMAGE = 'ghcr.io/your-org/laravel-upgrader:latest';

    private string $templatesDir;

    public function __construct(?string $templatesDir = null)
    {
        $this->templatesDir = $templatesDir ?? dirname(__DIR__, 2) . '/templates/ci';
        parent::__construct('ci:generate');
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'platform',
                null,
                InputOption::VALUE_REQUIRED,
                sprintf('Target CI platform (%s)', implode('|', self::PLATFORMS)),
            )
            ->addOption('from', null, InputOption::VALUE_OPTIONAL, 'Source Laravel version', '8')
            ->addOption('to', null, InputOption::VALUE_OPTIONAL, 'Target Laravel version', '9')
            ->addOption(
                'mode',
                null,
                InputOption::VALUE_OPTIONAL,
                sprintf('Upgrade mode (%s)', implode('|', self::MODES)),
                'dry-run',
            )
            ->addOption('image', null, InputOption::VALUE_OPTIONAL, 'Upgrader Docker image', self::DEFAULT_IMAGE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $platform = $input->getOption('platform');
        if (!is_string($platform) || $platform === '') {
            $output->writeln(sprintf(
                '<error>--platform is required. Valid options: %s</error>',
                implode(', ', self::PLATFORMS),
            ));
            return Command::INVALID;
        }

        if (!in_array($platform, self::PLATFORMS, true)) {
            $output->writeln(sprintf(
                '<error>Unknown platform "%s". Valid options: %s</error>',
                $platform,
                implode(', ', self::PLATFORMS),
            ));
            return Command::INVALID;
        }

        $fromRaw  = $input->getOption('from');
        $toRaw    = $input->getOption('to');
        $modeRaw  = $input->getOption('mode');
        $imageRaw = $input->getOption('image');

        $from  = is_string($fromRaw)  ? $fromRaw  : '8';
        $to    = is_string($toRaw)    ? $toRaw    : '9';
        $mode  = is_string($modeRaw)  ? $modeRaw  : 'dry-run';
        $image = is_string($imageRaw) ? $imageRaw : self::DEFAULT_IMAGE;

        if (!in_array($mode, self::MODES, true)) {
            $output->writeln(sprintf(
                '<error>Unknown mode "%s". Valid options: %s</error>',
                $mode,
                implode(', ', self::MODES),
            ));
            return Command::INVALID;
        }

        $yaml = $this->renderTemplate($platform, $from, $to, $mode, $image);
        $output->write($yaml);

        return Command::SUCCESS;
    }

    private function renderTemplate(string $platform, string $from, string $to, string $mode, string $image): string
    {
        $raw = match ($platform) {
            'github'    => $this->githubTemplate(),
            'gitlab'    => $this->gitlabTemplate(),
            'bitbucket' => $this->bitbucketTemplate(),
            default     => throw new \InvalidArgumentException("Unsupported platform: {$platform}"),
        };

        return strtr($raw, [
            '{{FROM_VERSION}}' => $from,
            '{{TO_VERSION}}'   => $to,
            '{{DEFAULT_MODE}}' => $mode,
            '{{IMAGE}}'        => $image,
        ]);
    }

    private function githubTemplate(): string
    {
        return $this->loadTemplate('github/github-actions.yml');
    }

    private function gitlabTemplate(): string
    {
        return $this->loadTemplate('gitlab/gitlab-ci.yml');
    }

    private function bitbucketTemplate(): string
    {
        return $this->loadTemplate('bitbucket/bitbucket-pipelines.yml');
    }

    private function loadTemplate(string $relativePath): string
    {
        $path = $this->templatesDir . '/' . $relativePath;
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Template file not found: {$path}");
        }
        return $content;
    }
}
