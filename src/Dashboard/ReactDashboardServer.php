<?php

declare(strict_types=1);

namespace App\Dashboard;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Socket\SocketServer;
use React\Stream\ThroughStream;
use Psr\Http\Message\ServerRequestInterface;

final class ReactDashboardServer
{
    private ?SocketServer $socket = null;
    private ?LoopInterface $loop = null;
    private ?\React\EventLoop\TimerInterface $heartbeatTimer = null;
    private ?\React\EventLoop\TimerInterface $logPollTimer = null;
    private int $logReadOffset = 0;
    private string $logRemainder = '';

    public function __construct(
        private readonly EventBus $eventBus,
        private readonly int $port = 8765,
        private readonly string $host = '127.0.0.1',
        private readonly string $publicPath = __DIR__ . '/public',
        private readonly ?string $logPath = null,
    ) {}

    /**
     * Start the ReactPHP event loop and HTTP server.
     * Blocks until stop() is called or loop is stopped externally.
     */
    public function start(): void
    {
        $this->loop = Loop::get();

        if ($this->logPath !== null && is_file($this->logPath)) {
            $size = filesize($this->logPath);
            $this->logReadOffset = $size !== false ? (int) $size : 0;
        }

        $http = new HttpServer(function (ServerRequestInterface $request): Response {
            return $this->handleRequest($request);
        });

        $this->socket = new SocketServer(
            $this->host . ':' . $this->port,
            [],
            $this->loop,
        );

        $http->listen($this->socket);

        // Periodic heartbeat every 30s to detect stale clients
        $this->heartbeatTimer = $this->loop->addPeriodicTimer(30, function (): void {
            $this->eventBus->broadcast(['event' => 'heartbeat', 'ts' => time()]);
        });

        if ($this->logPath !== null) {
            $this->logPollTimer = $this->loop->addPeriodicTimer(0.25, function (): void {
                $this->pollLogUpdates();
            });
        }

        $this->loop->run();
    }

    /**
     * Broadcast an event to all SSE clients via EventBus.
     *
     * @param array<string, mixed> $event
     */
    public function broadcast(array $event): void
    {
        $this->eventBus->broadcast($event);
    }

    /**
     * Stop the server (closes socket, stops loop).
     */
    public function stop(): void
    {
        if ($this->heartbeatTimer !== null && $this->loop !== null) {
            $this->loop->cancelTimer($this->heartbeatTimer);
            $this->heartbeatTimer = null;
        }
        if ($this->logPollTimer !== null && $this->loop !== null) {
            $this->loop->cancelTimer($this->logPollTimer);
            $this->logPollTimer = null;
        }
        $this->socket?->close();
        $this->loop?->stop();
    }

    /**
     * Open the dashboard in the system's default browser.
     * Supports: xdg-open (Linux), open (macOS), start (Windows).
     * Does NOT block — runs in background.
     */
    public function openBrowser(): void
    {
        $url = "http://{$this->host}:{$this->port}";
        $escaped = escapeshellarg($url);
        $os = PHP_OS_FAMILY;

        $cmd = match ($os) {
            'Linux'   => "xdg-open {$escaped} > /dev/null 2>&1 &",
            'Darwin'  => "open {$escaped} > /dev/null 2>&1 &",
            'Windows' => "start \"\" {$escaped}",
            default   => "xdg-open {$escaped} > /dev/null 2>&1 &",
        };

        if ($os === 'Windows') {
            pclose(popen($cmd, 'r'));
        } else {
            shell_exec($cmd);
        }
    }

    private function handleRequest(ServerRequestInterface $request): Response
    {
        $path = $request->getUri()->getPath();

        if (str_starts_with($path, '/static/')) {
            return $this->serveStatic($path);
        }

        if (str_starts_with($path, '/artifacts/')) {
            return $this->serveArtifact($path);
        }

        return match ($path) {
            '/'        => $this->serveIndex(),
            '/events'  => $this->serveEvents(),
            '/health'  => $this->serveHealth(),
            '/api/report' => $this->serveReportSummary(),
            default    => new Response(
                404,
                ['Content-Type' => 'application/json'],
                json_encode(['error' => 'not found']) ?: '{"error":"not found"}',
            ),
        };
    }

    private function serveStatic(string $path): Response
    {
        // Strip /static/ prefix, resolve relative to publicPath
        $relative = substr($path, strlen('/static/'));
        $realPublic = realpath($this->publicPath);

        if ($realPublic === false || $relative === '' || $relative === false) {
            return new Response(404, ['Content-Type' => 'text/plain'], 'Not found');
        }

        $filePath = realpath($this->publicPath . DIRECTORY_SEPARATOR . $relative);

        // Prevent directory traversal
        if ($filePath === false || !str_starts_with($filePath, $realPublic . DIRECTORY_SEPARATOR)) {
            return new Response(404, ['Content-Type' => 'text/plain'], 'Not found');
        }

        if (!is_file($filePath)) {
            return new Response(404, ['Content-Type' => 'text/plain'], 'Not found');
        }

        $ext = pathinfo($filePath, PATHINFO_EXTENSION);
        $mimeTypes = [
            'css'  => 'text/css',
            'js'   => 'application/javascript',
            'json' => 'application/json',
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'svg'  => 'image/svg+xml',
            'ico'  => 'image/x-icon',
        ];
        $contentType = $mimeTypes[$ext] ?? 'application/octet-stream';

        $content = file_get_contents($filePath);

        return new Response(
            200,
            ['Content-Type' => $contentType],
            $content !== false ? $content : '',
        );
    }

    private function serveArtifact(string $path): Response
    {
        $filename = basename(substr($path, strlen('/artifacts/')));
        if ($filename === '') {
            return new Response(404, ['Content-Type' => 'text/plain'], 'Not found');
        }

        foreach ($this->discoverArtifactPaths() as $artifactPath) {
            if (basename($artifactPath) !== $filename) {
                continue;
            }

            $content = file_get_contents($artifactPath);

            return new Response(
                200,
                ['Content-Type' => $this->artifactContentType($artifactPath)],
                $content !== false ? $content : '',
            );
        }

        return new Response(404, ['Content-Type' => 'text/plain'], 'Not found');
    }

    private function serveIndex(): Response
    {
        $indexPath = $this->publicPath . '/index.html';

        if (!is_file($indexPath)) {
            return new Response(
                500,
                ['Content-Type' => 'application/json'],
                json_encode(['error' => 'index.html not found']) ?: '{"error":"index.html not found"}',
            );
        }

        $content = file_get_contents($indexPath);

        return new Response(
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
            $content !== false ? $content : '',
        );
    }

    private function serveEvents(): Response
    {
        $stream = new ThroughStream();
        $id = uniqid('sse_', true);

        $this->eventBus->addClient($id, $stream);

        // Send initial heartbeat
        $stream->write('data: ' . json_encode(['event' => 'connected', 'ts' => time()]) . "\n\n");
        $this->replayLogToStream($stream);

        $stream->on('close', function () use ($id): void {
            $this->eventBus->removeClient($id);
        });

        return new Response(
            200,
            [
                'Content-Type'             => 'text/event-stream',
                'Cache-Control'            => 'no-cache',
                'Connection'               => 'keep-alive',
                'X-Accel-Buffering'        => 'no',
                'Access-Control-Allow-Origin' => '*',
            ],
            $stream,
        );
    }

    private function serveHealth(): Response
    {
        $body = json_encode([
            'status'  => 'ok',
            'clients' => $this->eventBus->clientCount(),
        ]);

        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            $body !== false ? $body : '{"status":"ok","clients":0}',
        );
    }

    private function serveReportSummary(): Response
    {
        $body = json_encode($this->buildReportSummary(), JSON_UNESCAPED_SLASHES);

        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            $body !== false ? $body : '{"available":false}',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildReportSummary(): array
    {
        $artifactPaths = $this->discoverArtifactPaths();
        $summary = [
            'available' => $artifactPaths !== [],
            'artifacts' => [],
            'changed_files' => [],
            'total_files_changed' => 0,
            'total_manual_review_items' => 0,
            'breaking_changes' => [],
        ];

        foreach ($artifactPaths as $key => $path) {
            $summary['artifacts'][$key] = [
                'name' => basename($path),
                'url' => '/artifacts/' . rawurlencode(basename($path)),
            ];
        }

        $changedFiles = [];
        $breakingChanges = [];
        $manualReviewTotal = 0;
        $filesChangedTotal = 0;

        $jsonPath = $artifactPaths['json'] ?? null;
        if ($jsonPath !== null) {
            $report = $this->readJsonFile($jsonPath);

            if (is_array($report) && isset($report['hops']) && is_array($report['hops'])) {
                $filesChangedTotal = (int) ($report['total_files_changed'] ?? 0);
                $manualReviewTotal = (int) ($report['total_manual_review_items'] ?? 0);

                foreach ($report['hops'] as $hopReport) {
                    if (!is_array($hopReport)) {
                        continue;
                    }

                    foreach ((array) ($hopReport['changed_files'] ?? []) as $file) {
                        if (is_string($file) && $file !== '') {
                            $changedFiles[$this->normalizeDisplayPath($file)] = true;
                        }
                    }

                    foreach ((array) ($hopReport['manual_review_items'] ?? []) as $item) {
                        if (!is_array($item)) {
                            continue;
                        }

                        $this->recordBreakingChange(
                            $breakingChanges,
                            (string) ($item['id'] ?? 'manual-review'),
                            'MANUAL',
                            (array) ($item['files'] ?? []),
                            null,
                        );
                    }
                }
            } elseif (is_array($report) && isset($report['summary']) && is_array($report['summary'])) {
                $filesChangedTotal = (int) ($report['summary']['files_changed'] ?? 0);
                $manualReviewTotal = count((array) ($report['manual_review_items'] ?? []));

                foreach ((array) ($report['file_scores'] ?? []) as $item) {
                    if (!is_array($item) || !is_string($item['file'] ?? null)) {
                        continue;
                    }

                    $changedFiles[$this->normalizeDisplayPath($item['file'])] = true;
                }

                foreach ((array) ($report['manual_review_items'] ?? []) as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $this->recordBreakingChange(
                        $breakingChanges,
                        (string) ($item['id'] ?? 'manual-review'),
                        'MANUAL',
                        (array) ($item['files'] ?? []),
                        null,
                    );
                }
            }
        }

        if ($this->logPath !== null && is_file($this->logPath)) {
            $logHandle = fopen($this->logPath, 'rb');
            if ($logHandle !== false) {
                while (($line = fgets($logHandle)) !== false) {
                    $event = json_decode(trim($line), true);
                    if (!is_array($event)) {
                        continue;
                    }

                    $eventName = (string) ($event['event'] ?? '');

                    if ($eventName === 'file_changed' && is_string($event['file'] ?? null)) {
                        $changedFiles[$this->normalizeDisplayPath((string) $event['file'])] = true;
                        continue;
                    }

                    if ($eventName === 'breaking_change_applied' || $eventName === 'manual_review_required') {
                        $type = $eventName === 'manual_review_required' || (bool) ($event['automated'] ?? false) === false
                            ? 'MANUAL'
                            : 'AUTO';

                        $this->recordBreakingChange(
                            $breakingChanges,
                            (string) ($event['id'] ?? $event['rule'] ?? $event['pattern'] ?? 'breaking-change'),
                            $type,
                            (array) ($event['files'] ?? []),
                            isset($event['file_count']) ? (int) $event['file_count'] : null,
                        );
                    }
                }

                fclose($logHandle);
            }
        }

        $summary['changed_files'] = array_keys($changedFiles);
        sort($summary['changed_files']);
        $summary['total_files_changed'] = $filesChangedTotal > 0 ? $filesChangedTotal : count($summary['changed_files']);
        $summary['total_manual_review_items'] = $manualReviewTotal > 0 ? $manualReviewTotal : count(array_filter(
            $breakingChanges,
            static fn (array $item): bool => $item['type'] === 'MANUAL',
        ));

        $breakingChangesList = array_values($breakingChanges);
        usort($breakingChangesList, static function (array $left, array $right): int {
            if ($left['type'] !== $right['type']) {
                return $left['type'] === 'MANUAL' ? -1 : 1;
            }

            return strcmp($left['rule'], $right['rule']);
        });

        $summary['breaking_changes'] = $breakingChangesList;

        return $summary;
    }

    /**
     * @return array<string, string>
     */
    private function discoverArtifactPaths(): array
    {
        if ($this->logPath === null) {
            return [];
        }

        $outputDir = dirname($this->logPath);
        $candidates = [
            'html' => ['chain-report.html', 'report.html'],
            'json' => ['chain-report.json', 'report.json'],
            'manual' => ['manual-review.md'],
            'audit' => ['audit.log.json', 'audit.jsonnd'],
        ];

        $paths = [];
        foreach ($candidates as $key => $filenames) {
            foreach ($filenames as $filename) {
                $path = $outputDir . DIRECTORY_SEPARATOR . $filename;
                if (is_file($path)) {
                    $paths[$key] = $path;
                    break;
                }
            }
        }

        return $paths;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJsonFile(string $path): ?array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, array{rule: string, type: string, files: list<string>, file_count: int|null}> $breakingChanges
     * @param list<mixed> $files
     */
    private function recordBreakingChange(array &$breakingChanges, string $rule, string $type, array $files, ?int $fileCount): void
    {
        $normalizedFiles = [];
        foreach ($files as $file) {
            if (!is_string($file) || $file === '') {
                continue;
            }

            $normalizedFiles[$this->normalizeDisplayPath($file)] = true;
        }

        $key = $type . '|' . $rule;
        $existing = $breakingChanges[$key] ?? [
            'rule' => $rule,
            'type' => $type,
            'files' => [],
            'file_count' => null,
        ];

        foreach (array_keys($normalizedFiles) as $normalizedFile) {
            $existing['files'][] = $normalizedFile;
        }

        $existing['files'] = array_values(array_unique($existing['files']));
        sort($existing['files']);

        $existing['file_count'] = max(
            $existing['file_count'] ?? 0,
            $fileCount ?? 0,
            count($existing['files']),
        );

        $breakingChanges[$key] = $existing;
    }

    private function normalizeDisplayPath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);

        foreach (['../repo/', '/repo/', 'repo/'] as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return substr($normalized, strlen($prefix));
            }
        }

        return ltrim($normalized, '/');
    }

    private function artifactContentType(string $path): string
    {
        return match (strtolower((string) pathinfo($path, PATHINFO_EXTENSION))) {
            'html' => 'text/html; charset=UTF-8',
            'json' => 'application/json',
            'md' => 'text/markdown; charset=UTF-8',
            default => 'text/plain; charset=UTF-8',
        };
    }

    private function replayLogToStream(ThroughStream $stream): void
    {
        if ($this->logPath === null || !is_file($this->logPath)) {
            return;
        }

        $handle = fopen($this->logPath, 'rb');
        if ($handle === false) {
            return;
        }

        while (($line = fgets($handle)) !== false) {
            $payload = $this->formatSsePayload($line);
            if ($payload !== null) {
                $stream->write($payload);
            }
        }

        fclose($handle);
    }

    private function pollLogUpdates(): void
    {
        if ($this->logPath === null || !is_file($this->logPath)) {
            return;
        }

        clearstatcache(true, $this->logPath);
        $size = filesize($this->logPath);
        if ($size === false) {
            return;
        }

        $currentSize = (int) $size;
        if ($currentSize < $this->logReadOffset) {
            $this->logReadOffset = 0;
            $this->logRemainder = '';
        }

        if ($currentSize === $this->logReadOffset) {
            return;
        }

        $handle = fopen($this->logPath, 'rb');
        if ($handle === false) {
            return;
        }

        if (fseek($handle, $this->logReadOffset) !== 0) {
            fclose($handle);
            return;
        }

        $chunk = stream_get_contents($handle);
        fclose($handle);

        if ($chunk === false || $chunk === '') {
            return;
        }

        $this->logReadOffset += strlen($chunk);

        $buffer = $this->logRemainder . $chunk;
        $lines = preg_split("/(\r\n|\n|\r)/", $buffer);
        if ($lines === false || $lines === []) {
            $this->logRemainder = $buffer;
            return;
        }

        $endsWithNewline = preg_match("/(\r\n|\n|\r)$/", $buffer) === 1;
        $this->logRemainder = $endsWithNewline ? '' : (string) array_pop($lines);

        foreach ($lines as $line) {
            $payload = $this->formatSsePayload($line);
            if ($payload === null) {
                continue;
            }

            $this->eventBus->broadcast(json_decode(substr($payload, 6), true, 512, JSON_THROW_ON_ERROR));
        }
    }

    private function formatSsePayload(string $line): ?string
    {
        $trimmed = trim($line);
        if ($trimmed === '') {
            return null;
        }

        $decoded = json_decode($trimmed, true);
        if (!is_array($decoded)) {
            return null;
        }

        $encoded = json_encode($decoded);
        if ($encoded === false) {
            return null;
        }

        return 'data: ' . $encoded . "\n\n";
    }
}
