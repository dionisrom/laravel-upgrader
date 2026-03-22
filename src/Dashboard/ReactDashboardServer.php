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

    public function __construct(
        private readonly EventBus $eventBus,
        private readonly int $port = 8765,
        private readonly string $host = '127.0.0.1',
        private readonly string $publicPath = __DIR__ . '/public',
    ) {}

    /**
     * Start the ReactPHP event loop and HTTP server.
     * Blocks until stop() is called or loop is stopped externally.
     */
    public function start(): void
    {
        $this->loop = Loop::get();

        $http = new HttpServer(function (ServerRequestInterface $request): Response {
            return $this->handleRequest($request);
        });

        $this->socket = new SocketServer(
            $this->host . ':' . $this->port,
            [],
            $this->loop,
        );

        $http->listen($this->socket);

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
        $os = PHP_OS_FAMILY;
        $cmd = match (true) {
            $os === 'Linux'  => "xdg-open {$url}",
            $os === 'Darwin' => "open {$url}",
            default          => "start {$url}",
        };
        // Run in background, suppress output
        shell_exec($cmd . ' > /dev/null 2>&1 &');
    }

    private function handleRequest(ServerRequestInterface $request): Response
    {
        $path = $request->getUri()->getPath();

        return match ($path) {
            '/'        => $this->serveIndex(),
            '/events'  => $this->serveEvents(),
            '/health'  => $this->serveHealth(),
            default    => new Response(
                404,
                ['Content-Type' => 'application/json'],
                json_encode(['error' => 'not found']) ?: '{"error":"not found"}',
            ),
        };
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
}
