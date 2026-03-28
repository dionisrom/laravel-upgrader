<?php

declare(strict_types=1);

namespace AppContainer\Report\Formatters;

use AppContainer\Report\ReportData;

final class AuditLogFormatter
{
    /**
     * Produce JSON-ND audit log enriched with run metadata.
     *
     * Each line is a standalone JSON object. Sensitive data (source code,
     * file contents, tokens, absolute paths) is stripped (TRD-REPORT-005).
     */
    public function format(ReportData $data): string
    {
        $enrichment = [
            'run_id'       => $data->runId,
            'host_version' => $data->hostVersion,
            'repo_sha'     => $data->repoSha,
        ];

        $lines = [];
        foreach ($data->auditEvents as $event) {
            $sanitised = $this->sanitise($event);
            $enriched  = array_merge($enrichment, $sanitised);
            $json      = json_encode($enriched, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if ($json !== false) {
                $lines[] = $json;
            }
        }

        // Always emit at least a summary event so the file is never empty.
        if (empty($lines)) {
            $summary = array_merge($enrichment, [
                'event'     => 'report_generated',
                'timestamp' => $data->timestamp,
            ]);
            $lines[] = (string) json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Strip fields that must never appear in the audit log.
     *
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function sanitise(array $event): array
    {
        $forbidden = ['source_code', 'file_contents', 'token', 'access_token', 'secret', 'password', 'contents'];

        foreach ($forbidden as $key) {
            unset($event[$key]);
        }

        return $event;
    }
}
