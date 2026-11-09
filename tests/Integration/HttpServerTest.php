<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Boots the real HTTP server (server.php) on a free port with a throwaway data dir,
 * creates a user with users.php and talks MCP to it over HTTP.
 * Catches breakage in ReactPHP / PSR-7 upgrades, routing and auth.
 */
final class HttpServerTest extends TestCase
{
    private static $proc;
    private static string $dataDir;
    private static string $base;
    private static string $token;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        self::$dataDir = sys_get_temp_dir() . '/mcp-test-' . bin2hex(random_bytes(4));
        mkdir(self::$dataDir);

        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
        fclose($sock);
        self::$base = "http://127.0.0.1:$port";

        $env = ['MCP_PORT' => (string) $port, 'MCP_DATA_DIR' => self::$dataDir, 'MCP_BASE_URL' => 'https://mcp.test', 'DEBUG' => 'false', 'PATH' => getenv('PATH')];

        // users.php creates the schema and the user; prints the token
        $out = shell_exec('MCP_DATA_DIR=' . escapeshellarg(self::$dataDir) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/users.php") . ' add tester 2>&1');
        preg_match('/\b([a-f0-9]{32,})\b/', (string) $out, $m);
        self::assertNotEmpty($m, "users.php did not print a token: $out");
        self::$token = $m[1];

        self::$proc = proc_open([PHP_BINARY, "$root/server.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', self::$dataDir . '/stderr.log', 'w']], $pipes, $root, $env);

        for ($i = 0; $i < 50; $i++) {
            if (@file_get_contents(self::$base . '/health') === 'OK') {
                return;
            }
            usleep(100_000);
        }
        self::fail('Server did not start: ' . @file_get_contents(self::$dataDir . '/stderr.log'));
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$proc)) {
            proc_terminate(self::$proc);
            proc_close(self::$proc);
        }
        array_map('unlink', glob(self::$dataDir . '/*'));
        @rmdir(self::$dataDir);
    }

    private function post(string $path, array $payload, ?string $token): array
    {
        $headers = "Content-Type: application/json\r\nAccept: application/json\r\n";
        if ($token !== null) {
            $headers .= "Authorization: Bearer $token\r\n";
        }
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => $headers, 'content' => json_encode($payload), 'ignore_errors' => true, 'timeout' => 10]]);
        $body = file_get_contents(self::$base . $path, false, $ctx);
        $status = (int) explode(' ', http_get_last_response_headers()[0])[1];
        return [$status, json_decode((string) $body, true)];
    }

    public function testHealth(): void
    {
        $this->assertSame('OK', file_get_contents(self::$base . '/health'));
    }

    public function testOAuthDiscoveryUsesBaseUrl(): void
    {
        $meta = json_decode(file_get_contents(self::$base . '/.well-known/oauth-authorization-server'), true);
        $this->assertSame('https://mcp.test/authorize', $meta['authorization_endpoint']);
        $this->assertSame('https://mcp.test/token', $meta['token_endpoint']);
    }

    public function testMcpRejectsMissingAndInvalidTokens(): void
    {
        [$status] = $this->post('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], null);
        $this->assertSame(401, $status);

        [$status] = $this->post('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], 'not-a-real-token');
        $this->assertSame(401, $status);
    }

    public function testMcpHandshakeAndToolsList(): void
    {
        [$status, $init] = $this->post('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-06-18', 'capabilities' => new stdClass(), 'clientInfo' => ['name' => 'phpunit', 'version' => '1'],
        ]], self::$token);
        $this->assertSame(200, $status);
        $this->assertArrayHasKey('serverInfo', $init['result']);

        [$status, $list] = $this->post('/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], self::$token);
        $this->assertSame(200, $status);
        $this->assertCount(113, $list['result']['tools']);
    }

    public function testToolCallReturnsStructuredResult(): void
    {
        [$status, $resp] = $this->post('/mcp', ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => [
            'name' => 'auth_status', 'arguments' => new stdClass(),
        ]], self::$token);

        $this->assertSame(200, $status);
        $status = json_decode($resp['result']['content'][0]['text'], true);
        $this->assertSame('✗ not configured', $status['rybbit']['status']);
    }

    public function testToolErrorIsReportedNotThrown(): void
    {
        [$status, $resp] = $this->post('/mcp', ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => [
            'name' => 'serpbear_get_domains', 'arguments' => new stdClass(),
        ]], self::$token);

        $this->assertSame(200, $status);
        $this->assertTrue($resp['result']['isError'] ?? false);
        $this->assertStringContainsString('SERPBEAR_BASE_URL', $resp['result']['content'][0]['text']);
    }
}
