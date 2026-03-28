<?php

declare(strict_types=1);

namespace App\Orchestrator;

final class AuditLogWriter implements EventConsumerInterface
{
    public function __construct(
        private readonly string $logPath,
        private readonly string $runId,
        private readonly string $repoSha,
        private readonly string $hostVersion = '',
    ) {}

    /**
     * Enriches the event with run metadata and appends one JSON line to the
     * audit log. Uses append mode (fopen 'a') so a crash cannot corrupt
     * previously written lines.
     *
     * @param array<string, mixed> $event
     */
    public function consume(array $event): void
    {
        /** @var array<string, mixed> $enriched */
        $enriched = array_merge($event, [
            'run_id'       => $this->runId,
            'host_version' => $this->hostVersion,
            'repo_sha'     => $this->repoSha,
            'host_ts'      => microtime(true),
        ]);

        $enriched = $this->sanitize($enriched);

        $encoded = json_encode($enriched, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($encoded === false) {
            error_log(sprintf(
                'AuditLogWriter: json_encode failed for run %s, event type %s',
                $this->runId,
                (string) ($event['event'] ?? 'unknown'),
            ));
            return;
        }

        $fh = fopen($this->logPath, 'a');

        if ($fh === false) {
            error_log(sprintf(
                'AuditLogWriter: failed to open log file for appending: %s',
                $this->logPath,
            ));
            return;
        }

        fwrite($fh, $encoded . "\n");
        fclose($fh);
    }

    /**
     * Remove sensitive fields from an event before writing to log (TRD-SEC-003).
     *
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function sanitize(array $event): array
    {
        $blocked = ['source_code', 'file_contents', 'content', 'token', 'password', 'secret', 'api_key', 'secret_key', 'private_key', 'auth_key'];

        foreach ($blocked as $field) {
            unset($event[$field]);
        }

        return $event;
    }
}
