<?php

declare(strict_types=1);

namespace App\Commands;

use App\Dashboard\EventBus;
use App\Dashboard\ReactDashboardServer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class DashboardCommand extends Command
{
    protected static string $defaultName = 'dashboard';
    protected static string $defaultDescription = 'Start the real-time upgrade dashboard server';

    public function __construct()
    {
        parent::__construct('dashboard');
    }

    protected function configure(): void
    {
        $this
            ->addOption('port',       null, InputOption::VALUE_OPTIONAL, 'Dashboard port', '8765')
            ->addOption('no-browser', null, InputOption::VALUE_NONE,     'Do not auto-open browser');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $portValue = $input->getOption('port');
        if (!is_string($portValue) || !ctype_digit($portValue) || (int) $portValue < 1 || (int) $portValue > 65535) {
            $output->writeln(sprintf('<error>Invalid port "%s". Must be an integer between 1 and 65535.</error>', (string) $portValue));
            return Command::INVALID;
        }
        $port      = (int) $portValue;
        $noBrowser = (bool) $input->getOption('no-browser');

        $eventBus = new EventBus();
        $server   = new ReactDashboardServer($eventBus, $port);

        if (!$noBrowser) {
            $server->openBrowser();
        }

        $output->writeln(sprintf(
            '<info>Dashboard running at http://localhost:%d — press Ctrl+C to stop</info>',
            $port,
        ));

        // Blocks on the ReactPHP event loop until Ctrl+C
        $server->start();

        return Command::SUCCESS;
    }
}
