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
require_once __DIR__ . '/src/Providers/TikTokAdsProvider.php';
require_once __DIR__ . '/src/Providers/AuthProvider.php';

set_error_handler(function (int $errno, string $errstr): bool {
    if ($errno !== E_USER_ERROR) {
        fwrite(STDERR, "[PHP $errno] $errstr\n");
        return true;
    }
    return false;
});
error_reporting(E_ALL);

// ─── Debug logging ────────────────────────────────────────────────────────────

function debugLog(string $level, string $msg): void
{
    static $enabled = null;
    if ($enabled === null) {
        $enabled = filter_var(getenv('DEBUG'), FILTER_VALIDATE_BOOLEAN);
    }
    if (!$enabled) return;

    $ts   = date('Y-m-d H:i:s');
    $line = "[$ts] [$level] $msg\n";
    $file = __DIR__ . '/data/debug.log';

    // Rotate log at 10 MB
    if (file_exists($file) && filesize($file) > 10 * 1024 * 1024) {
        @rename($file, $file . '.1');
    }
    file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    fwrite(STDERR, $line);
}

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
    // ── Claude Connector: auth codes with PKCE ─────────────────────────────────
    $db->exec("CREATE TABLE IF NOT EXISTS oauth_auth_codes (
        code           TEXT PRIMARY KEY,
        user_id        INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        code_challenge TEXT NOT NULL,
        expires_at     TEXT NOT NULL
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
    (new TikTokAdsProvider($env))->registerTools($server);
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
    // MCP_BASE_URL env var takes priority — set it in docker-compose.yml for reliability
    $envBase = getenv('MCP_BASE_URL');
    if ($envBase) {
        return rtrim($envBase, '/');
    }
    $scheme = $request->getHeaderLine('X-Forwarded-Proto') ?: 'http';
    $host   = $request->getHeaderLine('Host') ?: 'localhost';
    return "$scheme://$host";
}

// ─── Success page (shared by Google/Meta OAuth callbacks) ────────────────────

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

// ─── HTTP server ──────────────────────────────────────────────────────────────

$db   = openDb();
$port = (int) (getenv('MCP_PORT') ?: 8080);

// ─── Request / response logging middleware ──────────────────────────────────

$logMiddleware = function (ServerRequestInterface $request, callable $next): Response {
    $method  = $request->getMethod();
    $path    = $request->getUri()->getPath();
    $ip      = $request->getServerParams()['REMOTE_ADDR'] ?? '-';
    $authHdr = $request->getHeaderLine('Authorization');
    $authLog = $authHdr ? (substr($authHdr, 7, 8) . '***') : '-';
    $accept  = $request->getHeaderLine('Accept') ?: '-';
    $ct      = $request->getHeaderLine('Content-Type') ?: '-';
    debugLog('REQ', "$method $path  ip=$ip  auth=$authLog  accept=$accept  ct=$ct");

    /** @var Response $response */
    $response = $next($request);

    $status = $response->getStatusCode();
    if ($status >= 400) {
        $body = (string) $response->getBody();
        debugLog('ERR', "$status  $method $path  body=" . substr($body, 0, 300));
    } else {
        debugLog('RES', "$status  $method $path");
    }
    return $response;
};

$http = new HttpServer($logMiddleware, function (ServerRequestInterface $request) use ($db, $oauthSuccessHtml): Response {
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

    // ── Claude Connector: OAuth 2.0 Authorization Server Metadata ────────────
    // GET /.well-known/oauth-authorization-server
    // Claude automatically fetches this endpoint to discover the authorize/token URLs
    if ($path === '/.well-known/oauth-authorization-server' && $method === 'GET') {
        $base = baseUrl($request);
        return new Response(200,
            array_merge($corsHeaders, ['Content-Type' => 'application/json']),
            json_encode([
                'issuer'                                 => $base,
                'authorization_endpoint'                => "$base/authorize",
                'token_endpoint'                         => "$base/token",
                'registration_endpoint'                  => "$base/register",
                'response_types_supported'              => ['code'],
                'grant_types_supported'                 => ['authorization_code'],
                'code_challenge_methods_supported'      => ['S256'],
                'token_endpoint_auth_methods_supported' => ['none'],
            ], JSON_UNESCAPED_SLASHES)
        );
    }

    // ── Claude Connector: Authorization endpoint (GET) — login form ─────────────
    // Claude opens this URL in the user's browser
    if ($path === '/authorize' && $method === 'GET') {
        $q             = $request->getQueryParams();
        $state         = htmlspecialchars($q['state']          ?? '', ENT_QUOTES);
        $clientId      = htmlspecialchars($q['client_id']      ?? '', ENT_QUOTES);
        $redirectUri   = htmlspecialchars($q['redirect_uri']   ?? '', ENT_QUOTES);
        $codeChallenge = htmlspecialchars($q['code_challenge'] ?? '', ENT_QUOTES);
        $ccMethod      = htmlspecialchars($q['code_challenge_method'] ?? 'S256', ENT_QUOTES);

        $html = <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
          <meta charset="utf-8">
          <title>Marketing MCP — Login</title>
          <style>
            body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;
                 height:100vh;margin:0;background:#f8fafc}
            .card{background:#fff;border-radius:12px;padding:36px 44px;
                  box-shadow:0 4px 24px rgba(0,0,0,.10);min-width:320px}
            h2{margin-top:0;color:#1e293b}
            label{display:block;margin-bottom:6px;color:#475569;font-size:.9rem}
            input[type=password]{width:100%;box-sizing:border-box;padding:10px 12px;
                  border:1px solid #cbd5e1;border-radius:8px;font-size:1rem;margin-bottom:18px}
            button{width:100%;padding:11px;background:#2563eb;color:#fff;border:none;
                   border-radius:8px;font-size:1rem;cursor:pointer}
            button:hover{background:#1d4ed8}
          </style>
        </head>
        <body>
          <div class="card">
            <h2>Marketing MCP</h2>
            <form method="POST" action="/authorize">
              <input type="hidden" name="state"                  value="$state">
              <input type="hidden" name="client_id"              value="$clientId">
              <input type="hidden" name="redirect_uri"           value="$redirectUri">
              <input type="hidden" name="code_challenge"         value="$codeChallenge">
              <input type="hidden" name="code_challenge_method"  value="$ccMethod">
              <label for="token">Your access token</label>
              <input type="password" id="token" name="token" placeholder="Bearer token" required autofocus>
              <button type="submit">Authorize</button>
            </form>
          </div>
        </body>
        </html>
        HTML;

        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }

    // ── Claude Connector: Authorization endpoint (POST) — token validation ──────
    // Server validates the submitted token and redirects back to Claude with an auth code
    if ($path === '/authorize' && $method === 'POST') {
        $rawBody = (string) $request->getBody();
        parse_str($rawBody, $formData);

        $token         = trim($formData['token']          ?? '');
        $redirectUri   = trim($formData['redirect_uri']   ?? '');
        $state         = trim($formData['state']          ?? '');
        $codeChallenge = trim($formData['code_challenge'] ?? '');

        if ($token === '' || $redirectUri === '' || $codeChallenge === '') {
            return new Response(400, ['Content-Type' => 'text/plain'], 'Missing required fields.');
        }

        // Validate redirect_uri: must be localhost (MCP clients) or HTTPS
        $parsedUri = parse_url($redirectUri);
        $uriHost   = $parsedUri['host'] ?? '';
        $uriScheme = $parsedUri['scheme'] ?? '';
        $isLocalhost = in_array($uriHost, ['localhost', '127.0.0.1', '::1'], true);
        $isHttps     = $uriScheme === 'https';
        if (!$isLocalhost && !$isHttps) {
            return new Response(400, ['Content-Type' => 'text/plain'],
                'Invalid redirect_uri: must be a localhost URL or HTTPS URL.');
        }

        $user = resolveUser($db, $token);
        if ($user === null) {
            // Re-render the form with an error message
            $safeRedirect   = htmlspecialchars($redirectUri,   ENT_QUOTES);
            $safeState      = htmlspecialchars($state,         ENT_QUOTES);
            $safeChallenge  = htmlspecialchars($codeChallenge, ENT_QUOTES);
            $html = <<<HTML
            <!DOCTYPE html><html><head><meta charset="utf-8"><title>Marketing MCP — Login</title>
            <style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;
            height:100vh;margin:0;background:#f8fafc}.card{background:#fff;border-radius:12px;padding:36px 44px;
            box-shadow:0 4px 24px rgba(0,0,0,.10);min-width:320px}h2{margin-top:0;color:#1e293b}
            .err{color:#dc2626;margin-bottom:12px;font-size:.9rem}
            label{display:block;margin-bottom:6px;color:#475569;font-size:.9rem}
            input[type=password]{width:100%;box-sizing:border-box;padding:10px 12px;
            border:1px solid #fca5a5;border-radius:8px;font-size:1rem;margin-bottom:18px}
            button{width:100%;padding:11px;background:#2563eb;color:#fff;border:none;
            border-radius:8px;font-size:1rem;cursor:pointer}button:hover{background:#1d4ed8}</style></head>
            <body><div class="card"><h2>Marketing MCP</h2>
            <p class="err">&#10007; Invalid token. Please try again.</p>
            <form method="POST" action="/authorize">
              <input type="hidden" name="state"          value="$safeState">
              <input type="hidden" name="redirect_uri"   value="$safeRedirect">
              <input type="hidden" name="code_challenge" value="$safeChallenge">
              <label for="token">Your access token</label>
              <input type="password" id="token" name="token" placeholder="Bearer token" required autofocus>
              <button type="submit">Authorize</button>
            </form></div></body></html>
            HTML;
            return new Response(401, ['Content-Type' => 'text/html; charset=utf-8'], $html);
        }

        // Generate a one-time auth code (expires in 5 minutes)
        $code = bin2hex(random_bytes(32));
        $db->prepare(
            "INSERT INTO oauth_auth_codes (code, user_id, code_challenge, expires_at)
             VALUES (?, ?, ?, datetime('now', '+5 minutes'))"
        )->execute([$code, (int) $user['id'], $codeChallenge]);

        $location = $redirectUri . '?' . http_build_query(['code' => $code, 'state' => $state]);
        return new Response(302, ['Location' => $location], '');
    }

    // ── Claude Connector: Token endpoint ─────────────────────────────────────
    // Claude exchanges auth code + code_verifier → Bearer access_token
    if ($path === '/token' && $method === 'POST') {
        $rawBody = (string) $request->getBody();

        // Support both form-encoded and JSON body
        $contentType = $request->getHeaderLine('Content-Type');
        if (str_contains($contentType, 'application/json')) {
            $data = json_decode($rawBody, true) ?? [];
        } else {
            parse_str($rawBody, $data);
        }

        $code         = trim($data['code']          ?? '');
        $codeVerifier = trim($data['code_verifier'] ?? '');
        $grantType    = trim($data['grant_type']    ?? '');

        if ($grantType !== 'authorization_code') {
            return new Response(400,
                array_merge($corsHeaders, ['Content-Type' => 'application/json']),
                json_encode(['error' => 'unsupported_grant_type'])
            );
        }

        if ($code === '' || $codeVerifier === '') {
            return new Response(400,
                array_merge($corsHeaders, ['Content-Type' => 'application/json']),
                json_encode(['error' => 'invalid_request', 'error_description' => 'code and code_verifier are required'])
            );
        }

        // Look up the code in the DB (must not be expired)
        $stmt = $db->prepare(
            "SELECT * FROM oauth_auth_codes WHERE code = ? AND expires_at > datetime('now')"
        );
        $stmt->execute([$code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return new Response(400,
                array_merge($corsHeaders, ['Content-Type' => 'application/json']),
                json_encode(['error' => 'invalid_grant', 'error_description' => 'Code not found or expired'])
            );
        }

        // Verify PKCE S256: BASE64URL(SHA256(code_verifier)) == code_challenge
        $computed = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        if (!hash_equals($row['code_challenge'], $computed)) {
            return new Response(400,
                array_merge($corsHeaders, ['Content-Type' => 'application/json']),
                json_encode(['error' => 'invalid_grant', 'error_description' => 'PKCE verification failed'])
            );
        }

        // Delete the used code (single-use)
        $db->prepare("DELETE FROM oauth_auth_codes WHERE code = ?")->execute([$code]);

        // Return the user's existing Bearer token — /mcp works as before
        $stmt2 = $db->prepare("SELECT token FROM users WHERE id = ?");
        $stmt2->execute([(int) $row['user_id']]);
        $userRow = $stmt2->fetch(PDO::FETCH_ASSOC);

        return new Response(200,
            array_merge($corsHeaders, ['Content-Type' => 'application/json']),
            json_encode([
                'access_token' => $userRow['token'],
                'token_type'   => 'Bearer',
                'expires_in'   => 365 * 24 * 3600,
            ])
        );
    }

    // ── Google/Meta OAuth callback (called after browser-based authorization) ────
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

        try {
            $stmt = $db->prepare("SELECT state FROM oauth_pending WHERE state = ?");
            $stmt->execute([$state]);
            if (!$stmt->fetch()) {
                return new Response(400, ['Content-Type' => 'text/html'], '<h2>Invalid or expired state.</h2>');
            }

            $db->prepare("INSERT OR REPLACE INTO oauth_callbacks (state, code) VALUES (?, ?)")
               ->execute([$state, $code]);
        } catch (\Throwable $e) {
            fwrite(STDERR, "[oauth/callback error] " . $e->getMessage() . "\n");
            return new Response(500, ['Content-Type' => 'text/plain'], 'Internal error: ' . $e->getMessage());
        }

        return new Response(200, ['Content-Type' => 'text/html'], $oauthSuccessHtml);
    }

    // ── GET /mcp → 405 (SSE stream not supported, POST only) ──────────────────
    if ($path === '/mcp' && $method === 'GET') {
        return new Response(405,
            array_merge($corsHeaders, ['Allow' => 'POST', 'Content-Type' => 'text/plain']),
            'Method Not Allowed. Use POST.'
        );
    }

    // ── Dynamic Client Registration (RFC 7591) ───────────────────────────────
    // Claude Code and other MCP clients attempt POST /register before the OAuth flow.
    // We accept any registration and echo back a client_id (we don't enforce it).
    if ($path === '/register' && $method === 'POST') {
        $rawBody = (string) $request->getBody();
        $data    = json_decode($rawBody, true) ?? [];

        $clientId = 'mcp-client-' . bin2hex(random_bytes(8));
        return new Response(201,
            array_merge($corsHeaders, ['Content-Type' => 'application/json']),
            json_encode([
                'client_id'                => $clientId,
                'client_id_issued_at'      => time(),
                'redirect_uris'            => $data['redirect_uris'] ?? [],
                'token_endpoint_auth_method' => 'none',
                'grant_types'              => ['authorization_code'],
                'response_types'           => ['code'],
            ], JSON_UNESCAPED_SLASHES)
        );
    }

    // ── OAuth Protected Resource Metadata (RFC 9728) ──────────────────────────
    if ($path === '/.well-known/oauth-protected-resource' && $method === 'GET') {
        $base = baseUrl($request);
        return new Response(200,
            array_merge($corsHeaders, ['Content-Type' => 'application/json']),
            json_encode([
                'resource'              => $base,
                'authorization_servers' => [$base],
            ], JSON_UNESCAPED_SLASHES)
        );
    }

    // ── All other paths except /mcp → 404 ────────────────────────────────────
    if ($path !== '/mcp' || $method !== 'POST') {
        return new Response(404, array_merge($corsHeaders, ['Content-Type' => 'text/plain']), 'Not found');
    }

    // ── Bearer token auth ──────────────────────────────────────────────────────
    $authHeader = $request->getHeaderLine('Authorization');
    if (!str_starts_with($authHeader, 'Bearer ')) {
        $base = baseUrl($request);
        return new Response(401,
            array_merge($corsHeaders, [
                'Content-Type'     => 'application/json',
                'WWW-Authenticate' => "Bearer realm=\"Marketing MCP\", resource_metadata=\"$base/.well-known/oauth-protected-resource\"",
            ]),
            json_encode(['error' => 'Authorization: Bearer <token> required'])
        );
    }

    $token = substr($authHeader, 7);
    $user  = resolveUser($db, $token);
    if ($user === null) {
        $base = baseUrl($request);
        return new Response(401,
            array_merge($corsHeaders, [
                'Content-Type'     => 'application/json',
                'WWW-Authenticate' => "Bearer realm=\"Marketing MCP\", resource_metadata=\"$base/.well-known/oauth-protected-resource\"",
            ]),
            json_encode(['error' => 'Invalid token'])
        );
    }

    $userId = (int) $user['id'];
    $env    = loadUserEnv($db, $userId);
    $base   = baseUrl($request);
    debugLog('AUTH', "user={$user['name']} id=$userId");

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
    $rpcMethod = isset($jsonRpc['method']) ? $jsonRpc['method'] : 'batch[' . count($jsonRpc) . ']';
    debugLog('RPC', "user={$user['name']} method=$rpcMethod useSSE=" . ($useSSE ? 'yes' : 'no'));

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
fwrite(STDERR, "Endpoint:        POST http://localhost:$port/mcp  (Authorization: Bearer <token>)\n");
fwrite(STDERR, "OAuth metadata:  GET  http://localhost:$port/.well-known/oauth-authorization-server\n");
fwrite(STDERR, "OAuth authorize: GET  http://localhost:$port/authorize\n");
fwrite(STDERR, "OAuth token:     POST http://localhost:$port/token\n");
fwrite(STDERR, "OAuth callback:  GET  http://localhost:$port/oauth/callback\n");
fwrite(STDERR, "Debug logging:   " . (filter_var(getenv('DEBUG'), FILTER_VALIDATE_BOOLEAN) ? 'ENABLED → /app/data/debug.log' : 'disabled (set DEBUG=true to enable)') . "\n");

Loop::run();