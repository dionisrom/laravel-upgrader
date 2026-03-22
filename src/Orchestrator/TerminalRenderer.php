<?php

declare(strict_types=1);

namespace App\Orchestrator;

use Symfony\Component\Console\Output\OutputInterface;

final class TerminalRenderer implements EventConsumerInterface
{
    public function __construct(private readonly OutputInterface $output) {}

    /**
     * @param array<string, mixed> $event
     */
    public function consume(array $event): void
    {
        $type = (string) ($event['event'] ?? 'unknown');

        switch ($type) {
            case 'pipeline_start':
                $repo = (string) ($event['repo'] ?? 'unknown');
                $this->output->writeln(sprintf('<info>▶ Pipeline started for %s</info>', $repo));
                break;

            case 'pipeline_complete':
                $passed = (bool) ($event['passed'] ?? false);
                if ($passed) {
                    $this->output->writeln('<info>✓ Pipeline completed successfully.</info>');
                } else {
                    $this->output->writeln('<error>✗ Pipeline completed with failures.</error>');
                }
                break;

            case 'stage_start':
                $stage = (string) ($event['stage'] ?? 'unknown');
                $this->output->writeln(sprintf('<comment>  → Stage: %s</comment>', $stage));
                break;

            case 'stage_complete':
                $stage = (string) ($event['stage'] ?? 'unknown');
                $this->output->writeln(sprintf('<info>  ✓ Stage complete: %s</info>', $stage));
                break;

            case 'stage_error':
                $stage = (string) ($event['stage'] ?? 'unknown');
                $message = (string) ($event['message'] ?? '');
                $this->output->writeln(sprintf('<error>  ✗ Stage failed: %s — %s</error>', $stage, $message));
                break;

            case 'warning':
                $message = (string) ($event['message'] ?? '');
                $this->output->writeln(sprintf('<comment>[WARN] %s</comment>', $message));
                break;

            case 'stderr':
                /** @var list<mixed> $lines */
                $lines = (array) ($event['lines'] ?? []);
                foreach ($lines as $line) {
                    $this->output->writeln(sprintf('<error>[STDERR] %s</error>', (string) $line));
                }
                break;

            case 'hop_skipped':
                $hop = (string) ($event['hop'] ?? '');
                $this->output->writeln(sprintf(
                    '<question>  ↷ Hop already completed, skipping: %s</question>',
                    $hop,
                ));
                break;

            default:
                $json = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $this->output->writeln(sprintf(
                    '<comment>[%s] %s</comment>',
                    $type,
                    $json !== false ? $json : '{}',
                ));
                break;
        }
    }
}
