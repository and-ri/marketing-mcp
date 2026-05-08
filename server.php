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
require_once __DIR__ . '/src/Providers/AuthProvider.php';

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
    $db->exec("CREATE TABLE IF NOT EXISTS oauth_pending (
        state      TEXT PRIMARY KEY,
        user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        platform   TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS oauth_callbacks (
        state       TEXT PRIMARY KEY,
        code        TEXT NOT NULL,
        received_at TEXT NOT NULL DEFAULT (datetime('now'))
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

// ─── Helpers ─────────────────────────────────────────────────────────────────

function buildServer(array $env, PDO $db, int $userId, string $baseUrl): McpServer
{
    $server = new McpServer();
    (new GoogleAdsProvider($env))->registerTools($server);
    (new GoogleAnalyticsProvider($env))->registerTools($server);
    (new MetaAdsProvider($env))->registerTools($server);
    (new AuthProvider($env, $db, $userId, $baseUrl))->registerTools($server);
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

function baseUrl(ServerRequestInterface $request): string
{
    $scheme = $request->getHeaderLine('X-Forwarded-Proto') ?: 'http';
    $host   = $request->getHeaderLine('Host') ?: 'localhost';
    return "$scheme://$host";
}

// ─── Success page (shared by OAuth callbacks) ────────────────────────────────

$oauthSuccessHtml = <<<HTML
<!DOCTYPE html><html><head><meta charset="utf-8"><title>Authorized</title>
<style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;
height:100vh;margin:0;background:#f0fdf4}.card{background:#fff;border-radius:12px;
padding:32px 40px;box-shadow:0 4px 20px rgba(0,0,0,.08);text-align:center}
h2{color:#16a34a;margin-top:0}p{color:#555}</style></head>
<body><div class="card"><h2>&#10003; Authorized!</h2>
<p>You can close this tab.<br>Call <code>auth_finish_google</code> or <code>auth_finish_meta</code> in Claude to save the credentials.</p>
</div></body></html>
HTML;

// ─── HTTP server ─────────────────────────────────────────────────────────────

$db   = openDb();
$port = (int) (getenv('MCP_PORT') ?: 8080);

$http = new HttpServer(function (ServerRequestInterface $request) use ($db, $oauthSuccessHtml): Response {
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

    // ── OAuth callback (called by Google/Meta after browser auth) ────────────
    if ($path === '/oauth/callback' && $method === 'GET') {
        $params = $request->getQueryParams();
        $state  = $params['state'] ?? '';
        $code   = $params['code']  ?? '';
        $error  = $params['error'] ?? '';

        if ($error) {
            $msg = htmlspecialchars($params['error_description'] ?? $error);
            return new Response(400, ['Content-Type' => 'text/html'],
                "<h2>Authorization failed: $msg</h2>");
        }

        if (!$state || !$code) {
            return new Response(400, ['Content-Type' => 'text/html'], '<h2>Missing state or code.</h2>');
        }

        // Verify this state was registered by a known user
        $stmt = $db->prepare("SELECT id FROM oauth_pending WHERE state = ?");
        $stmt->execute([$state]);
        if (!$stmt->fetch()) {
            return new Response(400, ['Content-Type' => 'text/html'], '<h2>Invalid or expired state.</h2>');
        }

        $db->prepare("INSERT OR REPLACE INTO oauth_callbacks (state, code) VALUES (?, ?)")
            ->execute([$state, $code]);

        return new Response(200, ['Content-Type' => 'text/html'], $oauthSuccessHtml);
    }

    if ($path !== '/mcp' || $method !== 'POST') {
        return new Response(404, array_merge($corsHeaders, ['Content-Type' => 'text/plain']), 'Not found');
    }

    // ── Bearer token auth ────────────────────────────────────────────────────
    $authHeader = $request->getHeaderLine('Authorization');
    if (!str_starts_with($authHeader, 'Bearer ')) {
        return jsonErr(401, 'Authorization: Bearer <token> required');
    }

    $token = substr($authHeader, 7);
    $user  = resolveUser($db, $token);
    if ($user === null) {
        return jsonErr(401, 'Invalid token');
    }

    $userId  = (int) $user['id'];
    $env     = loadUserEnv($db, $userId);
    $base    = baseUrl($request);

    // ── Parse JSON-RPC ───────────────────────────────────────────────────────
    $body    = (string) $request->getBody();
    $jsonRpc = json_decode($body, true);
    if (!is_array($jsonRpc)) {
        $resp = json_encode(mcpError(null, -32700, 'Parse error'), JSON_UNESCAPED_UNICODE);
        return new Response(400, array_merge($corsHeaders, ['Content-Type' => 'application/json']), $resp);
    }

    $server = buildServer($env, $db, $userId, $base);
    $accept = $request->getHeaderLine('Accept');
    $useSSE = str_contains($accept, 'text/event-stream');

    // ── Batch ────────────────────────────────────────────────────────────────
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
                'Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache',
            ]), "data: $encoded\n\n");
        }
        return new Response(200, array_merge($corsHeaders, ['Content-Type' => 'application/json']), $encoded);
    }

    // ── Single request ───────────────────────────────────────────────────────
    if (!array_key_exists('id', $jsonRpc)) {
        return new Response(202, $corsHeaders, '');
    }

    $result  = $server->processRequest($jsonRpc);
    $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($useSSE) {
        return new Response(200, array_merge($corsHeaders, [
            'Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache',
        ]), "data: $encoded\n\n");
    }

    return new Response(200, array_merge($corsHeaders, ['Content-Type' => 'application/json']), $encoded);
});

$socket = new SocketServer("0.0.0.0:$port");
$http->listen($socket);

$addr = $socket->getAddress();
fwrite(STDERR, "Marketing MCP HTTP server listening on $addr\n");
fwrite(STDERR, "Endpoint:       POST http://localhost:$port/mcp  (Authorization: Bearer <token>)\n");
fwrite(STDERR, "OAuth callback: GET  http://localhost:$port/oauth/callback\n");

Loop::run();
