<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use App\Dashboard\EventBus;
use App\Dashboard\ReactDashboardServer;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use React\Http\Message\Response;

/**
 * Tests for HTTP routing, SSE headers, static serving, and health endpoint.
 */
final class ReactDashboardServerHttpTest extends TestCase
{
    private function invokeHandleRequest(ReactDashboardServer $server, string $path): Response
    {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn($path);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $ref = new \ReflectionMethod($server, 'handleRequest');
        $ref->setAccessible(true);

        return $ref->invoke($server, $request);
    }

    private function makeServer(?string $publicPath = null): ReactDashboardServer
    {
        $bus = new EventBus();
        $path = $publicPath ?? dirname(__DIR__, 3) . '/src/Dashboard/public';

        return new ReactDashboardServer($bus, 8765, '127.0.0.1', $path);
    }

    public function testIndexReturns200WithHtml(): void
    {
        $server = $this->makeServer();
        $response = $this->invokeHandleRequest($server, '/');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
    }

    public function testIndexReturns500WhenFileNotFound(): void
    {
        $server = $this->makeServer(sys_get_temp_dir() . '/nonexistent_dashboard_' . uniqid());
        $response = $this->invokeHandleRequest($server, '/');

        self::assertSame(500, $response->getStatusCode());
    }

    public function testEventsReturnsSseHeaders(): void
    {
        $server = $this->makeServer();
        $response = $this->invokeHandleRequest($server, '/events');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/event-stream', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-cache', $response->getHeaderLine('Cache-Control'));
        self::assertSame('no', $response->getHeaderLine('X-Accel-Buffering'));
    }

    public function testHealthReturnsJsonWithClientCount(): void
    {
        $server = $this->makeServer();
        $response = $this->invokeHandleRequest($server, '/health');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('ok', $body['status']);
        self::assertSame(0, $body['clients']);
    }

    public function testApiReportReturnsNormalizedSummaryFromOutputArtifacts(): void
    {
        $baseDir = sys_get_temp_dir() . '/dashboard-api-' . uniqid('', true);
        mkdir($baseDir, 0755, true);
        file_put_contents($baseDir . '/audit.jsonnd', json_encode([
            'event' => 'breaking_change_applied',
            'id' => 'BC-123',
            'file_count' => 2,
        ]) . "\n");
        file_put_contents($baseDir . '/report.html', '<html>report</html>');
        file_put_contents($baseDir . '/report.json', json_encode([
            'summary' => ['files_changed' => 2],
            'manual_review_items' => [
                ['id' => 'MR-001', 'files' => ['app/Http/Kernel.php']],
            ],
            'file_scores' => [
                ['file' => 'app/Http/Kernel.php'],
                ['file' => '../repo/config/app.php'],
            ],
        ], JSON_THROW_ON_ERROR));

        $server = new ReactDashboardServer(new EventBus(), 8765, '127.0.0.1', dirname(__DIR__, 3) . '/src/Dashboard/public', $baseDir . '/audit.jsonnd');
        $response = $this->invokeHandleRequest($server, '/api/report');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($body['available']);
        self::assertSame('/artifacts/report.html', $body['artifacts']['html']['url']);
        self::assertSame(['app/Http/Kernel.php', 'config/app.php'], $body['changed_files']);
        self::assertSame(2, $body['total_files_changed']);
        self::assertContains('MR-001', array_column($body['breaking_changes'], 'rule'));
        self::assertContains('BC-123', array_column($body['breaking_changes'], 'rule'));
    }

    public function testUnknownRouteReturns404(): void
    {
        $server = $this->makeServer();
        $response = $this->invokeHandleRequest($server, '/nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    public function testStaticServesExistingFile(): void
    {
        // The .gitkeep file exists in public/
        $server = $this->makeServer();
        $response = $this->invokeHandleRequest($server, '/static/.gitkeep');

        self::assertSame(200, $response->getStatusCode());
    }

    public function testStaticDirectoryTraversalReturns404(): void
    {
        $server = $this->makeServer();
        $response = $this->invokeHandleRequest($server, '/static/../../../etc/passwd');

        self::assertSame(404, $response->getStatusCode());
    }

    public function testStaticNonexistentFileReturns404(): void
    {
        $server = $this->makeServer();
        $response = $this->invokeHandleRequest($server, '/static/does-not-exist.js');

        self::assertSame(404, $response->getStatusCode());
    }
}
