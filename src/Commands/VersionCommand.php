<?php

declare(strict_types=1);

namespace App\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class VersionCommand extends Command
{
    protected static string $defaultName = 'version';
    protected static string $defaultDescription = 'Show tool and bundled rule set versions';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(sprintf('Laravel Enterprise Upgrader v%s', RunCommand::VERSION));
        $output->writeln('Bundled Rector rules: L8→L9 (4 custom + upstream driftingly/rector-laravel:1.2.6)');
        $output->writeln(sprintf('PHP: %s', PHP_VERSION));

        return Command::SUCCESS;
    }
}
