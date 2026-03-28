<?php

declare(strict_types=1);

namespace App\Commands;

use App\Composer\FrameworkDetector;
use App\Composer\LaravelVersionDetector;
use App\Composer\PhpConstraintDetector;
use App\Dashboard\EventBus;
use App\Dashboard\ReactDashboardServer;
use App\Orchestrator\AuditLogWriter;
use App\Orchestrator\DockerRunner;
use App\Orchestrator\EventStreamer;
use App\Orchestrator\HopPlanner;
use App\Orchestrator\OrchestratorException;
use App\Orchestrator\State\TransformCheckpoint;
use App\Orchestrator\TerminalRenderer;
use App\Orchestrator\UpgradeOptions;
use App\Orchestrator\UpgradeOrchestrator;
use App\Repository\RepositoryFetcherFactory;
use App\Workspace\WorkspaceManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

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

            $confirmation = trim((string) $this->readLine($input));
            if ($confirmation !== 'I understand PHPStan will not run') {
                $safeOutput->writeln('<error>Confirmation not matched. Aborting.</error>');
                return Command::INVALID;
            }
        }

        /** @var string $repo */
        $repo     = (string) $input->getOption('repo');
        $from     = $input->getOption('from') !== null ? (string) $input->getOption('from') : null;
        $to       = (string) $input->getOption('to');
        $outputDir = (string) $input->getOption('output');
        $noDashboard = (bool) $input->getOption('no-dashboard');
        $dryRun   = (bool) $input->getOption('dry-run');
        $resume   = (bool) $input->getOption('resume');
        $withArtisanVerify = (bool) $input->getOption('with-artisan-verify');
        $formats  = array_map('trim', explode(',', (string) $input->getOption('format')));
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
                $from ?? 'auto-detect',
                $to,
                $dashboardLine,
                rtrim($outputDir, '/'),
            ));

            // 5. Wait for ENTER
            $this->readLine($input);
        }

        // 6. Create output directory if missing
        if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true)) {
            $safeOutput->writeln(sprintf('<error>Failed to create output directory: %s</error>', $outputDir));
            return Command::INVALID;
        }

        // 7. Set up EventStreamer with TerminalRenderer + AuditLogWriter
        $streamer = new EventStreamer();
        $streamer->addConsumer(new TerminalRenderer($safeOutput, $repo));

        $logPath = rtrim($outputDir, '/') . '/audit.jsonnd';
        $repoSha = substr(hash('sha256', $repo . time()), 0, 12);
        $streamer->addConsumer(new AuditLogWriter($logPath, uniqid('run-', true), $repoSha, $this->getApplication()?->getVersion() ?? 'unknown'));

        // 8. Start dashboard if not disabled
        $dashboardProcess = null;
        if (!$noDashboard) {
            $dashboardProcess = $this->startDashboardServer($logPath, $port, $safeOutput);
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

        $frameworkDetector = new FrameworkDetector();
        $framework = $frameworkDetector->detect($fetchResult->workspacePath);
        if ($framework === 'lumen_ambiguous') {
            $safeOutput->writeln('<error>Detected ambiguous Lumen markers in composer.json or bootstrap/app.php. The upgrader cannot safely choose the Laravel hop path for this repository. Aborting.</error>');
            return Command::FAILURE;
        }

        if ($framework === 'lumen') {
            $safeOutput->writeln('<info>Detected a Lumen application. Routing to the dedicated Lumen migration pipeline.</info>');
        }

        $phpConstraintDetector = new PhpConstraintDetector();
        $phpConstraint = $phpConstraintDetector->detect($fetchResult->workspacePath);
        if ($phpConstraint !== null) {
            $safeOutput->writeln(sprintf('<info>Detected PHP requirement from composer.json: %s</info>', $phpConstraint));
        }

        // 9b. Auto-detect --from version if not provided
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

        // 10. Run orchestrator
        $workspaceManager = new WorkspaceManager();
        $checkpoints = $resume ? new TransformCheckpoint($fetchResult->workspacePath) : null;
        $orchestrator = new UpgradeOrchestrator(
            $framework === 'lumen'
                ? new HopPlanner(
                    hopImages: ['8:9' => 'upgrader/lumen-migrator'],
                    phpConstraint: $phpConstraint,
                    frameworkType: 'lumen',
                )
                : new HopPlanner(phpConstraint: $phpConstraint),
            new DockerRunner(),
            $workspaceManager,
            $streamer,
            $checkpoints,
        );

        $upgradeOptions = new UpgradeOptions(
            skipPhpstan: $skipPhpstan,
            withArtisanVerify: $withArtisanVerify,
            reportFormats: $formats,
            dryRun: $dryRun,
            repoLabel: $repo,
        );

        try {
            $orchestrator->run($fetchResult->workspacePath, $from, $to, $upgradeOptions);
        } catch (OrchestratorException $e) {
            $safeOutput->writeln(sprintf('<error>Upgrade failed: %s</error>', $this->redactor->redact($e->getMessage())));
            return Command::FAILURE;
        }

        $this->copyReportArtifacts($fetchResult->workspacePath, $outputDir);

        $safeOutput->writeln(sprintf('<info>Upgrade complete. Output written to %s/</info>', rtrim($outputDir, '/')));

        return Command::SUCCESS;
    }

    private function readLine(InputInterface $input): string
    {
        if ($input instanceof \Symfony\Component\Console\Input\StreamableInputInterface && $input->getStream() !== null) {
            return (string) fgets($input->getStream());
        }

        return (string) fgets(STDIN);
    }

    private function startDashboardServer(string $logPath, int $port, OutputInterface $output): ?Process
    {
        $binPath = dirname(__DIR__, 2) . '/bin/upgrader';

        if (!is_file($binPath)) {
            $output->writeln('<comment>Dashboard disabled: launcher script not found.</comment>');
            return null;
        }

        $process = new Process([
            PHP_BINARY,
            $binPath,
            'dashboard',
            '--port',
            (string) $port,
            '--log',
            $logPath,
            '--no-browser',
        ], dirname(__DIR__, 2));

        $process->disableOutput();

        try {
            $process->start();
            $this->waitForDashboardReady('127.0.0.1', $port, 5.0);
            (new ReactDashboardServer(new EventBus(), $port))->openBrowser();

            return $process;
        } catch (\Throwable $e) {
            $output->writeln(sprintf(
                '<comment>Dashboard failed to start: %s</comment>',
                $this->redactor->redact($e->getMessage()),
            ));

            return null;
        }
    }

    private function waitForDashboardReady(string $host, int $port, float $timeoutSeconds): void
    {
        $deadline = microtime(true) + $timeoutSeconds;

        do {
            $socket = @fsockopen($host, $port, $errno, $errstr, 0.25);
            if (is_resource($socket)) {
                fclose($socket);
                return;
            }

            usleep(100000);
        } while (microtime(true) < $deadline);
    }

    private function copyReportArtifacts(string $workspacePath, string $outputDir): void
    {
        foreach ($this->discoverReportArtifacts($workspacePath) as $filename => $sourcePath) {
            $destinationPath = rtrim($outputDir, '/\\') . DIRECTORY_SEPARATOR . $filename;
            @copy($sourcePath, $destinationPath);
        }
    }

    /**
     * @return array<string, string>
     */
    private function discoverReportArtifacts(string $workspacePath): array
    {
        $artifacts = [];
        $candidateRoots = [
            rtrim($workspacePath, '/\\') . DIRECTORY_SEPARATOR . '.upgrader',
            rtrim($workspacePath, '/\\'),
        ];
        $filenames = [
            'chain-report.html',
            'chain-report.json',
            'report.html',
            'report.json',
            'manual-review.md',
            'audit.log.json',
        ];

        foreach ($candidateRoots as $root) {
            foreach ($filenames as $filename) {
                if (isset($artifacts[$filename])) {
                    continue;
                }

                $path = $root . DIRECTORY_SEPARATOR . $filename;
                if (is_file($path)) {
                    $artifacts[$filename] = $path;
                }
            }
        }

        return $artifacts;
    }
}
