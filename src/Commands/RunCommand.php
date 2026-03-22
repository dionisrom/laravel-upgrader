<?php

declare(strict_types=1);

namespace App\Commands;

use App\Dashboard\EventBus;
use App\Dashboard\ReactDashboardServer;
use App\Orchestrator\AuditLogWriter;
use App\Orchestrator\DockerRunner;
use App\Orchestrator\EventStreamer;
use App\Orchestrator\HopPlanner;
use App\Orchestrator\OrchestratorException;
use App\Orchestrator\TerminalRenderer;
use App\Orchestrator\UpgradeOrchestrator;
use App\Repository\RepositoryFetcherFactory;
use App\Workspace\WorkspaceManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class RunCommand extends Command
{
    public const VERSION = '1.0.0';
    private const DASHBOARD_PORT = 8765;

    protected static string $defaultName = 'run';
    protected static string $defaultDescription = 'Execute the full Laravel upgrade pipeline';

    private readonly InputValidator $validator;
    private readonly TokenRedactor $redactor;

    public function __construct()
    {
        parent::__construct('run');
        $this->validator = new InputValidator();
        $this->redactor  = new TokenRedactor();
    }

    protected function configure(): void
    {
        $this
            ->addOption('repo',                null, InputOption::VALUE_REQUIRED, 'Repository source (path, github:org/repo, gitlab:org/repo)')
            ->addOption('token',               null, InputOption::VALUE_OPTIONAL, 'Auth token (or set UPGRADER_TOKEN env var)')
            ->addOption('from',                null, InputOption::VALUE_OPTIONAL, 'Source Laravel version (auto-detected if omitted)')
            ->addOption('to',                  null, InputOption::VALUE_OPTIONAL, 'Target Laravel version', '9')
            ->addOption('dry-run',             null, InputOption::VALUE_NONE,     'Analyse only — no transforms applied')
            ->addOption('resume',              null, InputOption::VALUE_NONE,     'Resume from last checkpoint')
            ->addOption('no-dashboard',        null, InputOption::VALUE_NONE,     'Disable the real-time dashboard')
            ->addOption('output',              null, InputOption::VALUE_OPTIONAL, 'Output directory', './upgrader-output')
            ->addOption('format',              null, InputOption::VALUE_OPTIONAL, 'Report formats: html,json,md', 'html,json,md')
            ->addOption('with-artisan-verify', null, InputOption::VALUE_NONE,     'Run artisan route:list verification (opt-in)')
            ->addOption('skip-phpstan',        null, InputOption::VALUE_NONE,     'Skip PHPStan verification (requires confirmation)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // 1. Validate inputs
        $options = [
            'repo'   => $input->getOption('repo'),
            'from'   => $input->getOption('from'),
            'to'     => $input->getOption('to'),
            'output' => $input->getOption('output'),
            'format' => $input->getOption('format'),
        ];

        $errors = $this->validator->validate($options);
        if ($errors !== []) {
            foreach ($errors as $error) {
                $output->writeln(sprintf('<error>%s</error>', $error));
            }
            return Command::INVALID;
        }

        // 2. Resolve token: --token flag OR UPGRADER_TOKEN env var
        $token = $input->getOption('token');
        if (!is_string($token) || $token === '') {
            $envToken = getenv('UPGRADER_TOKEN');
            $token = is_string($envToken) && $envToken !== '' ? $envToken : null;
        }

        if (is_string($token) && $token !== '') {
            $this->redactor->addToken($token);
        }

        $safeOutput = $this->redactor->wrapOutput($output);

        // 3. Handle --skip-phpstan confirmation
        $skipPhpstan = (bool) $input->getOption('skip-phpstan');
        if ($skipPhpstan && !$input->isInteractive()) {
            // --no-interaction: skip confirmation, proceed
        } elseif ($skipPhpstan) {
            $safeOutput->writeln('<comment>You have requested to skip PHPStan verification.</comment>');
            $safeOutput->write('Type "I understand PHPStan will not run" to confirm: ');

            $confirmation = trim((string) fgets(STDIN));
            if ($confirmation !== 'I understand PHPStan will not run') {
                $safeOutput->writeln('<error>Confirmation not matched. Aborting.</error>');
                return Command::INVALID;
            }
        }

        /** @var string $repo */
        $repo     = (string) $input->getOption('repo');
        $from     = $input->getOption('from') !== null ? (string) $input->getOption('from') : '8';
        $to       = (string) $input->getOption('to');
        $outputDir = (string) $input->getOption('output');
        $noDashboard = (bool) $input->getOption('no-dashboard');
        $port     = self::DASHBOARD_PORT;

        // 4. Show pre-flight summary (unless --no-interaction)
        if ($input->isInteractive()) {
            $dashboardLine = $noDashboard
                ? 'disabled'
                : sprintf('http://localhost:%d', $port);

            $safeOutput->writeln(sprintf(
                "\nLaravel Enterprise Upgrader v%s\n%s\n  Repository:  %s\n  From:        Laravel %s\n  To:          Laravel %s\n  Dashboard:   %s\n  Output:      %s/\n\nEstimated time: 8–15 minutes for a typical repository.\nPress ENTER to confirm, or Ctrl+C to cancel.",
                self::VERSION,
                str_repeat('═', 38),
                $this->redactor->redact($repo),
                $from,
                $to,
                $dashboardLine,
                rtrim($outputDir, '/'),
            ));

            // 5. Wait for ENTER
            fgets(STDIN);
        }

        // 6. Create output directory if missing
        if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true)) {
            $safeOutput->writeln(sprintf('<error>Failed to create output directory: %s</error>', $outputDir));
            return Command::INVALID;
        }

        // 7. Set up EventStreamer with TerminalRenderer + AuditLogWriter
        $streamer = new EventStreamer();
        $streamer->addConsumer(new TerminalRenderer($safeOutput));

        $logPath = rtrim($outputDir, '/') . '/audit.jsonnd';
        $repoSha = substr(hash('sha256', $repo . time()), 0, 12);
        $streamer->addConsumer(new AuditLogWriter($logPath, uniqid('run-', true), $repoSha));

        // 8. Start dashboard if not disabled
        $dashboardServer = null;
        if (!$noDashboard) {
            $eventBus = new EventBus();
            $dashboardServer = new ReactDashboardServer($eventBus, $port);
            $streamer->addConsumer($eventBus);
            $dashboardServer->openBrowser();
        }

        // 9. Fetch repository
        $factory = new RepositoryFetcherFactory();
        $fetcher = $factory->make($repo);

        $workspacePath = sys_get_temp_dir() . '/upgrader-workspace-' . uniqid('', true);
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

        // 10. Run orchestrator
        $workspaceManager = new WorkspaceManager();
        $orchestrator = new UpgradeOrchestrator(
            new HopPlanner(),
            new DockerRunner(),
            $workspaceManager,
            $streamer,
        );

        try {
            $orchestrator->run($fetchResult->workspacePath, $from, $to);
        } catch (OrchestratorException $e) {
            $safeOutput->writeln(sprintf('<error>Upgrade failed: %s</error>', $this->redactor->redact($e->getMessage())));
            return Command::FAILURE;
        }

        $safeOutput->writeln(sprintf('<info>Upgrade complete. Output written to %s/</info>', rtrim($outputDir, '/')));

        return Command::SUCCESS;
    }
}
