<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Google\Auth\CredentialsLoader;
use Google\Auth\OAuth2;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Socket\SocketServer;

// ─── helpers ────────────────────────────────────────────────────────────────

function ask(string $prompt, string $default = ''): string
{
    $hint = $default !== '' ? " [$default]" : '';
    echo $prompt . $hint . ': ';
    $value = trim(fgets(STDIN));
    return $value !== '' ? $value : $default;
}

function openBrowser(string $url): void
{
    match (PHP_OS_FAMILY) {
        'Darwin'  => exec("open " . escapeshellarg($url)),
        'Windows' => exec("start " . escapeshellarg($url)),
        default   => exec("xdg-open " . escapeshellarg($url)),
    };
}

function readEnv(): array
{
    $path = __DIR__ . '/.env';
    if (!file_exists($path)) {
        return [];
    }
    $env = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $val] = explode('=', $line, 2);
        $env[trim($key)] = trim($val);
    }
    return $env;
}

function writeEnv(array $values): void
{
    $path    = __DIR__ . '/.env';
    $merged  = array_merge(readEnv(), $values);
    $lines   = array_map(fn($k, $v) => "$k=$v", array_keys($merged), $merged);
    file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL);
}

/**
 * Open a local TCP socket on a random port, then start an HTTP server.
 * The OAuth2 object is built by $oauthFactory AFTER the port is known,
 * so the redirect URI is always absolute from the start.
 *
 * @param callable(string $redirectUrl): OAuth2 $oauthFactory
 * @return array{OAuth2, SocketServer, callable(): array}
 */
function startOAuthServer(callable $oauthFactory, string $successHtml): array
{
    $socket      = new SocketServer('127.0.0.1:0');
    $redirectUrl = str_replace('tcp://', 'https://', $socket->getAddress());

    // replace 127.0.0.1 with localhost for better compatibility with some OAuth2 providers (e.g. Meta)
    $redirectUrl = str_replace('127.0.0.1', 'localhost', $redirectUrl);

    $oauth2 = $oauthFactory($redirectUrl);
    $result = [];

    $server = new HttpServer(
        function (ServerRequestInterface $request) use ($oauth2, $successHtml, &$result) {
            $params = $request->getQueryParams();

            $state = $params['state'] ?? '';
            if (empty($state) || $state !== $oauth2->getState()) {
                return new Response(400, ['Content-Type' => 'text/plain'], 'Invalid state parameter.');
            }

            if (!isset($params['code'])) {
                $error = $params['error_description'] ?? $params['error'] ?? 'Unknown error';
                Loop::stop();
                return new Response(400, ['Content-Type' => 'text/plain'], "Auth failed: $error");
            }

            $oauth2->setCode($params['code']);
            $result = $oauth2->fetchAuthToken();
            Loop::stop();

            return new Response(200, ['Content-Type' => 'text/html'], $successHtml);
        }
    );

    $server->listen($socket);

    $wait = function () use (&$result) {
        Loop::run();
        return $result;
    };

    return [$oauth2, $socket, $wait];
}

// ─── success page ────────────────────────────────────────────────────────────

$successHtml = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>Auth successful</title>
<style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;background:#f0fdf4}
.card{background:#fff;border-radius:12px;padding:32px 40px;box-shadow:0 4px 20px rgba(0,0,0,.08);text-align:center}
h2{color:#16a34a;margin-top:0}p{color:#555}</style></head>
<body><div class="card"><h2>&#10003; Authorized!</h2><p>You can close this tab and return to the terminal.</p></div></body>
</html>
HTML;

// ─── menu ────────────────────────────────────────────────────────────────────

echo PHP_EOL;
echo "╔══════════════════════════════════════════╗" . PHP_EOL;
echo "║     Marketing MCP — Auth Setup           ║" . PHP_EOL;
echo "╚══════════════════════════════════════════╝" . PHP_EOL;
echo PHP_EOL;
echo "What do you want to authorize?" . PHP_EOL;
echo "  [1] Google Ads + Google Analytics (one OAuth2 flow)" . PHP_EOL;
echo "  [2] Meta Ads (Facebook / Instagram)" . PHP_EOL;
echo "  [3] Both" . PHP_EOL;
echo PHP_EOL;
$choice = ask('Your choice', '3');

// ─── Google OAuth2 ───────────────────────────────────────────────────────────

if (in_array($choice, ['1', '3'])) {
    echo PHP_EOL;
    echo "── Google OAuth2 ──────────────────────────────────────────" . PHP_EOL;
    echo "Create a client ID at https://console.cloud.google.com → APIs & Services → Credentials" . PHP_EOL;
    echo "Type: Desktop app (no redirect URI setup needed)." . PHP_EOL;
    echo PHP_EOL;

    $current      = readEnv();
    $clientId     = ask('Client ID',     $current['GOOGLE_ADS_CLIENT_ID'] ?? '');
    $clientSecret = ask('Client secret', $current['GOOGLE_ADS_CLIENT_SECRET'] ?? '');
    $devToken     = ask('Google Ads developer token (blank to skip)', $current['GOOGLE_ADS_DEVELOPER_TOKEN'] ?? '');
    $mccId        = ask('Manager account (MCC) customer ID — blank if none', $current['GOOGLE_ADS_LOGIN_CUSTOMER_ID'] ?? '');

    $scopes = implode(' ', [
        'https://www.googleapis.com/auth/adwords',
        'https://www.googleapis.com/auth/analytics.readonly',
    ]);

    [$oauth2, $socket, $wait] = startOAuthServer(
        function (string $redirectUrl) use ($clientId, $clientSecret, $scopes): OAuth2 {
            return new OAuth2([
                'clientId'           => $clientId,
                'clientSecret'       => $clientSecret,
                'authorizationUri'   => 'https://accounts.google.com/o/oauth2/v2/auth',
                'redirectUri'        => $redirectUrl,
                'tokenCredentialUri' => CredentialsLoader::TOKEN_CREDENTIAL_URI,
                'scope'              => $scopes,
                'state'              => sha1(openssl_random_pseudo_bytes(1024)),
            ]);
        },
        $successHtml
    );

    $authUrl = $oauth2->buildFullAuthorizationUri([
        'access_type' => 'offline',
        'prompt'      => 'consent',
    ]);

    echo PHP_EOL;
    echo "Opening browser…" . PHP_EOL;
    echo "If it doesn't open, visit:" . PHP_EOL;
    echo PHP_EOL . "  $authUrl" . PHP_EOL . PHP_EOL;
    openBrowser((string) $authUrl);

    echo "Waiting for Google callback…" . PHP_EOL;
    $tokens = $wait();
    $socket->close();

    if (empty($tokens['refresh_token'])) {
        echo "ERROR: no refresh_token received. Try revoking previous access at https://myaccount.google.com/permissions and re-running." . PHP_EOL;
        exit(1);
    }

    $toSave = [
        'GOOGLE_ADS_CLIENT_ID'     => $clientId,
        'GOOGLE_ADS_CLIENT_SECRET' => $clientSecret,
        'GOOGLE_ADS_REFRESH_TOKEN' => $tokens['refresh_token'],
        'GOOGLE_CLIENT_ID'         => $clientId,
        'GOOGLE_CLIENT_SECRET'     => $clientSecret,
        'GOOGLE_REFRESH_TOKEN'     => $tokens['refresh_token'],
    ];
    if ($devToken !== '') {
        $toSave['GOOGLE_ADS_DEVELOPER_TOKEN'] = $devToken;
    }
    if ($mccId !== '') {
        $toSave['GOOGLE_ADS_LOGIN_CUSTOMER_ID'] = $mccId;
    }

    writeEnv($toSave);

    echo PHP_EOL;
    echo "✓ Google credentials saved to .env" . PHP_EOL;
    echo "  Refresh token: " . substr($tokens['refresh_token'], 0, 12) . "…" . PHP_EOL;
}

// ─── Meta OAuth2 ────────────────────────────────────────────────────────────
// Facebook requires HTTPS for the redirect URI, so a local server won't work.
// We use the "paste URL" approach: the browser will show a connection error, but
// the URL containing the code will be in the address bar — copy and paste it here.

if (in_array($choice, ['2', '3'])) {
    echo PHP_EOL;
    echo "── Meta (Facebook) OAuth2 ─────────────────────────────────" . PHP_EOL;
    echo "Create a Meta app at https://developers.facebook.com → Add 'Marketing API' product." . PHP_EOL;
    echo PHP_EOL;

    $current      = readEnv();
    $metaAppId    = ask('Meta App ID',           $current['META_APP_ID'] ?? '');
    $metaSecret   = ask('Meta App secret',       $current['META_APP_SECRET'] ?? '');
    $metaRedirect = ask('Redirect URI (registered in Meta app)', $current['META_REDIRECT_URI'] ?? 'https://localhost/callback');

    $state   = sha1(openssl_random_pseudo_bytes(1024));
    $authUrl = 'https://www.facebook.com/dialog/oauth?' . http_build_query([
        'client_id'     => $metaAppId,
        'redirect_uri'  => $metaRedirect,
        'scope'         => 'ads_read,ads_management,business_management',
        'response_type' => 'code',
        'state'         => $state,
    ]);

    echo PHP_EOL;
    echo "Make sure this URI is in Meta app → Facebook Login → Valid OAuth Redirect URIs:" . PHP_EOL;
    echo "  $metaRedirect" . PHP_EOL;
    echo PHP_EOL;
    echo "Opening browser…" . PHP_EOL;
    echo "After you authorize, the browser will fail to connect (that's OK!)." . PHP_EOL;
    echo "Copy the full URL from the browser address bar and paste it here." . PHP_EOL;
    echo PHP_EOL . "  $authUrl" . PHP_EOL . PHP_EOL;
    openBrowser($authUrl);

    $pastedUrl = ask('Paste the full redirect URL here');

    // Extract code from pasted URL
    $parsedUrl   = parse_url($pastedUrl);
    $queryString = $parsedUrl['query'] ?? '';
    parse_str($queryString, $params);

    if (empty($params['code'])) {
        echo "ERROR: no 'code' found in the URL. URL received: $pastedUrl" . PHP_EOL;
        exit(1);
    }
    if (!empty($params['state']) && $params['state'] !== $state) {
        echo "WARNING: state mismatch — possible CSRF. Continuing anyway." . PHP_EOL;
    }

    $code = $params['code'];
    echo PHP_EOL . "Exchanging code for token…" . PHP_EOL;

    $ctx = stream_context_create(['http' => ['ignore_errors' => true]]);

    // Step 1: code → short-lived user token
    $tokenUrl = 'https://graph.facebook.com/oauth/access_token?' . http_build_query([
        'client_id'     => $metaAppId,
        'client_secret' => $metaSecret,
        'redirect_uri'  => $metaRedirect,
        'code'          => $code,
    ]);
    $resp = json_decode(file_get_contents($tokenUrl, false, $ctx), true);

    if (!isset($resp['access_token'])) {
        echo "ERROR exchanging code: " . json_encode($resp) . PHP_EOL;
        exit(1);
    }

    // Step 2: short-lived → long-lived (~60 days)
    $exchangeUrl = 'https://graph.facebook.com/oauth/access_token?' . http_build_query([
        'grant_type'        => 'fb_exchange_token',
        'client_id'         => $metaAppId,
        'client_secret'     => $metaSecret,
        'fb_exchange_token' => $resp['access_token'],
    ]);
    $exchanged = json_decode(file_get_contents($exchangeUrl, false, $ctx), true);
    $longToken = $exchanged['access_token'] ?? $resp['access_token'];

    writeEnv([
        'META_APP_ID'       => $metaAppId,
        'META_APP_SECRET'   => $metaSecret,
        'META_REDIRECT_URI' => $metaRedirect,
        'META_ACCESS_TOKEN' => $longToken,
    ]);

    $expiresIn = isset($exchanged['expires_in'])
        ? round($exchanged['expires_in'] / 86400) . ' days'
        : 'unknown';

    echo PHP_EOL;
    echo "✓ Meta credentials saved to .env" . PHP_EOL;
    echo "  Token expires in: $expiresIn" . PHP_EOL;
    echo "  Token: " . substr($longToken, 0, 20) . "…" . PHP_EOL;
}

// ─── done ────────────────────────────────────────────────────────────────────

echo PHP_EOL;
echo "╔══════════════════════════════════════════╗" . PHP_EOL;
echo "║  Done! Run: php app.php to start MCP     ║" . PHP_EOL;
echo "╚══════════════════════════════════════════╝" . PHP_EOL;
echo PHP_EOL;
