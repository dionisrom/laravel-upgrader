<?php

declare(strict_types=1);

namespace App\Commands;

use App\Composer\LaravelVersionDetector;
use App\Orchestrator\AuditLogWriter;
use App\Orchestrator\DockerRunner;
use App\Orchestrator\EventStreamer;
use App\Orchestrator\HopPlanner;
use App\Orchestrator\OrchestratorException;
use App\Orchestrator\TerminalRenderer;
use App\Orchestrator\UpgradeOptions;
use App\Orchestrator\UpgradeOrchestrator;
use App\Repository\RepositoryFetcherFactory;
use App\Workspace\WorkspaceManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class AnalyseCommand extends Command
{
    protected static string $defaultName = 'analyse';
    protected static string $defaultDescription = 'Analyse repository — dry-run, no code changes';

    private readonly InputValidator $validator;
    private readonly TokenRedactor $redactor;

    public function __construct()
    {
        parent::__construct('analyse');
        $this->validator = new InputValidator();
        $this->redactor  = new TokenRedactor();
    }

    protected function configure(): void
    {
        $this
            ->addOption('repo',         null, InputOption::VALUE_REQUIRED, 'Repository source (path, github:org/repo, gitlab:org/repo)')
            ->addOption('token',        null, InputOption::VALUE_OPTIONAL, 'Auth token (or set UPGRADER_TOKEN env var)')
            ->addOption('from',         null, InputOption::VALUE_OPTIONAL, 'Source Laravel version (auto-detected if omitted)')
            ->addOption('to',           null, InputOption::VALUE_OPTIONAL, 'Target Laravel version', '9')
            ->addOption('no-dashboard', null, InputOption::VALUE_NONE,     'Disable the real-time dashboard')
            ->addOption('output',       null, InputOption::VALUE_OPTIONAL, 'Output directory', './upgrader-output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // 1. Validate inputs
        $options = [
            'repo'   => $input->getOption('repo'),
            'from'   => $input->getOption('from'),
            'to'     => $input->getOption('to'),
            'output' => $input->getOption('output'),
        ];

        $errors = $this->validator->validate($options);
        if ($errors !== []) {
            foreach ($errors as $error) {
                $output->writeln(sprintf('<error>%s</error>', $error));
            }
            return Command::INVALID;
        }

        // 2. Resolve token
        $token = $input->getOption('token');
        if (!is_string($token) || $token === '') {
            $envToken = getenv('UPGRADER_TOKEN');
            $token = is_string($envToken) && $envToken !== '' ? $envToken : null;
        }

        if (is_string($token) && $token !== '') {
            $this->redactor->addToken($token);
        }

        $safeOutput = $this->redactor->wrapOutput($output);

        /** @var string $repo */
        $repo      = (string) $input->getOption('repo');
        $from      = $input->getOption('from') !== null ? (string) $input->getOption('from') : null;
        $to        = (string) $input->getOption('to');
        $outputDir = (string) $input->getOption('output');

        // 3. Show pre-flight summary (unless --no-interaction)
        if ($input->isInteractive()) {
            $safeOutput->writeln(sprintf(
                "\nLaravel Enterprise Upgrader v%s — DRY RUN (no changes will be written)\n%s\n  Repository:  %s\n  From:        Laravel %s\n  To:          Laravel %s\n  Output:      %s/\n\nPress ENTER to continue, or Ctrl+C to cancel.",
                RunCommand::VERSION,
                str_repeat('═', 38),
                $this->redactor->redact($repo),
                $from ?? 'auto-detect',
                $to,
                rtrim($outputDir, '/'),
            ));

            fgets(STDIN);
        }

        // 4. Create output directory if missing
        if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true)) {
            $safeOutput->writeln(sprintf('<error>Failed to create output directory: %s</error>', $outputDir));
            return Command::INVALID;
        }

        // 5. Set up EventStreamer (dry-run: TerminalRenderer only, no write-back)
        $streamer = new EventStreamer();
        $streamer->addConsumer(new TerminalRenderer($safeOutput));

        $logPath = rtrim($outputDir, '/') . '/audit.jsonnd';
        $repoSha = substr(hash('sha256', $repo . time()), 0, 12);
        $streamer->addConsumer(new AuditLogWriter($logPath, uniqid('analyse-', true), $repoSha, $this->getApplication()?->getVersion() ?? 'unknown'));

        // 6. Fetch repository
        $factory = new RepositoryFetcherFactory();
        $fetcher = $factory->make($repo);

        $workspacePath = sys_get_temp_dir() . '/upgrader-analyse-' . uniqid('', true);
        if (!mkdir($workspacePath, 0700, true)) {
            $safeOutput->writeln('<error>Failed to create workspace directory.</error>');
            return Command::FAILURE;
        }

        try {
            $fetchResult = $fetcher->fetch($repo, $workspacePath, $token);
        } catch (\Throwable $e) {
            $safeOutput->writeln(sprintf('<error>Repository fetch failed: %s</error>', $this->redactor->redact($e->getMessage())));
            return Command::FAILURE;
        }

        // 6b. Auto-detect --from version if not provided
        if ($from === null) {
            $detector = new LaravelVersionDetector();
            $detected = $detector->detect($fetchResult->workspacePath);
            if ($detected === null) {
                $safeOutput->writeln('<error>Could not auto-detect Laravel version from composer.lock. Please specify --from explicitly.</error>');
                return Command::INVALID;
            }
            $from = $detected;
            $safeOutput->writeln(sprintf('<info>Auto-detected source version: Laravel %s</info>', $from));
        }

        // 7. Run orchestrator in dry-run mode (no Docker hops, no writeBack)
        $workspaceManager = new WorkspaceManager();
        $orchestrator = new UpgradeOrchestrator(
            new HopPlanner(),
            new DockerRunner(),
            $workspaceManager,
            $streamer,
        );

        $safeOutput->writeln('<info>Running analysis (dry-run, no transforms will be applied)...</info>');

        try {
            $orchestrator->run($fetchResult->workspacePath, $from, $to, new UpgradeOptions(dryRun: true));
        } catch (OrchestratorException $e) {
            $safeOutput->writeln(sprintf('<error>Analysis failed: %s</error>', $this->redactor->redact($e->getMessage())));
            return Command::FAILURE;
        }

        $safeOutput->writeln('<info>Analysis complete. No changes were written.</info>');

        return Command::SUCCESS;
    }
}
