<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Socket\SocketServer;

require_once __DIR__ . '/src/McpServer.php';
require_once __DIR__ . '/src/Providers/GoogleAdsProvider.php';
require_once __DIR__ . '/src/Providers/GoogleAnalyticsProvider.php';
require_once __DIR__ . '/src/Providers/MetaAdsProvider.php';

set_error_handler(function (int $errno, string $errstr): bool {
    if ($errno !== E_USER_ERROR) {
        fwrite(STDERR, "[PHP $errno] $errstr\n");
        return true;
    }
    return false;
});
error_reporting(E_ALL);

// ─── Database ────────────────────────────────────────────────────────────────

function openDb(): PDO
{
    $path = __DIR__ . '/data/users.sqlite';
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }
    $db = new PDO("sqlite:$path");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA foreign_keys = ON");
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        name       TEXT UNIQUE NOT NULL,
        token      TEXT UNIQUE NOT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS user_env (
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        key     TEXT NOT NULL,
        value   TEXT NOT NULL,
        PRIMARY KEY (user_id, key)
    )");
    return $db;
}

function resolveUser(PDO $db, string $token): ?array
{
    $stmt = $db->prepare("SELECT * FROM users WHERE token = ?");
    $stmt->execute([$token]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function loadUserEnv(PDO $db, int $userId): array
{
    $stmt = $db->prepare("SELECT key, value FROM user_env WHERE user_id = ?");
    $stmt->execute([$userId]);
    $env = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $env[$row['key']] = $row['value'];
    }
    return $env;
}

// ─── MCP helpers ─────────────────────────────────────────────────────────────

function buildServer(array $env): McpServer
{
    $server = new McpServer();
    (new GoogleAdsProvider($env))->registerTools($server);
    (new GoogleAnalyticsProvider($env))->registerTools($server);
    (new MetaAdsProvider($env))->registerTools($server);
    return $server;
}

function jsonErr(int $status, string $message): Response
{
    return new Response($status, ['Content-Type' => 'application/json'],
        json_encode(['error' => $message]));
}

function mcpError(mixed $id, int $code, string $message): array
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
}

// ─── HTTP server ─────────────────────────────────────────────────────────────

$db   = openDb();
$port = (int) (getenv('MCP_PORT') ?: 8080);

$http = new HttpServer(function (ServerRequestInterface $request) use ($db): Response {
    $path   = $request->getUri()->getPath();
    $method = $request->getMethod();

    $corsHeaders = [
        'Access-Control-Allow-Origin'  => '*',
        'Access-Control-Allow-Methods' => 'POST, GET, OPTIONS',
        'Access-Control-Allow-Headers' => 'Authorization, Content-Type, Accept',
    ];

    if ($method === 'OPTIONS') {
        return new Response(204, $corsHeaders, '');
    }

    if ($path === '/health' && $method === 'GET') {
        return new Response(200, array_merge($corsHeaders, ['Content-Type' => 'text/plain']), 'OK');
    }

    if ($path !== '/mcp' || $method !== 'POST') {
        return new Response(404, array_merge($corsHeaders, ['Content-Type' => 'text/plain']), 'Not found');
    }

    // ── Auth ────────────────────────────────────────────────────────────────
    $authHeader = $request->getHeaderLine('Authorization');
    if (!str_starts_with($authHeader, 'Bearer ')) {
        return jsonErr(401, 'Authorization: Bearer <token> required');
    }

    $token = substr($authHeader, 7);
    $user  = resolveUser($db, $token);
    if ($user === null) {
        return jsonErr(401, 'Invalid token');
    }

    $env = loadUserEnv($db, (int) $user['id']);

    // ── Parse JSON-RPC ──────────────────────────────────────────────────────
    $body    = (string) $request->getBody();
    $jsonRpc = json_decode($body, true);
    if (!is_array($jsonRpc)) {
        $resp = json_encode(mcpError(null, -32700, 'Parse error'), JSON_UNESCAPED_UNICODE);
        return new Response(400, array_merge($corsHeaders, ['Content-Type' => 'application/json']), $resp);
    }

    $server = buildServer($env);
    $accept = $request->getHeaderLine('Accept');
    $useSSE = str_contains($accept, 'text/event-stream');

    // ── Batch ───────────────────────────────────────────────────────────────
    if (isset($jsonRpc[0]) && is_array($jsonRpc[0])) {
        $responses = [];
        foreach ($jsonRpc as $req) {
            if (is_array($req) && array_key_exists('id', $req)) {
                $responses[] = $server->processRequest($req);
            }
        }
        $encoded = json_encode($responses, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($useSSE) {
            return new Response(200, array_merge($corsHeaders, [
                'Content-Type'  => 'text/event-stream',
                'Cache-Control' => 'no-cache',
            ]), "data: $encoded\n\n");
        }
        return new Response(200, array_merge($corsHeaders, ['Content-Type' => 'application/json']), $encoded);
    }

    // ── Single request ──────────────────────────────────────────────────────
    if (!array_key_exists('id', $jsonRpc)) {
        // Notification — no response body
        return new Response(202, $corsHeaders, '');
    }

    $result  = $server->processRequest($jsonRpc);
    $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($useSSE) {
        return new Response(200, array_merge($corsHeaders, [
            'Content-Type'  => 'text/event-stream',
            'Cache-Control' => 'no-cache',
        ]), "data: $encoded\n\n");
    }

    return new Response(200, array_merge($corsHeaders, ['Content-Type' => 'application/json']), $encoded);
});

$socket = new SocketServer("0.0.0.0:$port");
$http->listen($socket);

$addr = $socket->getAddress();
fwrite(STDERR, "Marketing MCP HTTP server listening on $addr\n");
fwrite(STDERR, "Endpoint: POST http://localhost:$port/mcp  (Authorization: Bearer <token>)\n");

Loop::run();
