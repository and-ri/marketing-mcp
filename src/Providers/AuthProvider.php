<?php

use Google\Auth\CredentialsLoader;
use Google\Auth\OAuth2;

/**
 * MCP tools that let users authorize Google and Meta directly from Claude,
 * without needing shell access to the server.
 *
 * Flow (Google):
 *   1. auth_start_google  → returns an OAuth URL; user opens it in a browser
 *   2. browser redirects to /oauth/callback → server stores the code in SQLite
 *   3. auth_finish_google → exchanges the code for a refresh_token, saves to user_env
 *
 * Flow (Meta):
 *   Same pattern: auth_start_meta → browser → auth_finish_meta
 *
 * The redirect URI that must be registered in each OAuth app is:
 *   https://yourdomain.com/oauth/callback
 */
class AuthProvider
{
    private array  $env;
    private PDO    $db;
    private int    $userId;
    private string $baseUrl;

    public function __construct(array $env, PDO $db, int $userId, string $baseUrl)
    {
        $this->env     = $env;
        $this->db      = $db;
        $this->userId  = $userId;
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    // ─── Internal helpers ─────────────────────────────────────────────────────

    private function callbackUrl(): string
    {
        return $this->baseUrl . '/oauth/callback';
    }

    private function saveEnv(array $values): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO user_env (user_id, key, value) VALUES (?, ?, ?)
             ON CONFLICT(user_id, key) DO UPDATE SET value = excluded.value"
        );
        foreach ($values as $key => $value) {
            $stmt->execute([$this->userId, $key, $value]);
        }
        // Keep $this->env in sync so subsequent calls in the same request see updates
        foreach ($values as $key => $value) {
            $this->env[$key] = $value;
        }
    }

    private function freshEnv(): array
    {
        $stmt = $this->db->prepare("SELECT key, value FROM user_env WHERE user_id = ?");
        $stmt->execute([$this->userId]);
        $env = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $env[$row['key']] = $row['value'];
        }
        return $env;
    }

    private function storePending(string $state, string $platform): void
    {
        // Clean up stale pending entries for this user+platform (older than 30 min)
        $this->db->prepare(
            "DELETE FROM oauth_pending
             WHERE user_id = ? AND platform = ?
               AND created_at < datetime('now', '-30 minutes')"
        )->execute([$this->userId, $platform]);

        $this->db->prepare(
            "INSERT OR REPLACE INTO oauth_pending (state, user_id, platform) VALUES (?, ?, ?)"
        )->execute([$state, $this->userId, $platform]);
    }

    private function consumeCode(string $platform): array
    {
        $stmt = $this->db->prepare(
            "SELECT op.state, oc.code
             FROM oauth_pending op
             LEFT JOIN oauth_callbacks oc ON oc.state = op.state
             WHERE op.user_id = ? AND op.platform = ?
             ORDER BY op.created_at DESC LIMIT 1"
        );
        $stmt->execute([$this->userId, $platform]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return ['status' => 'no_pending'];
        }
        if (!$row['code']) {
            return ['status' => 'waiting', 'state' => $row['state']];
        }

        // Clean up so the same code can't be reused
        $this->db->prepare("DELETE FROM oauth_pending  WHERE state = ?")->execute([$row['state']]);
        $this->db->prepare("DELETE FROM oauth_callbacks WHERE state = ?")->execute([$row['state']]);

        return ['status' => 'ready', 'code' => $row['code']];
    }

    // ─── Tool registration ────────────────────────────────────────────────────

    public function registerTools(McpServer $server): void
    {
        $server->registerTool(
            'auth_start_google',
            'Begin Google OAuth2 authorization (Google Ads + Analytics). Returns a URL to open in a browser. '
            . 'After authorizing, call auth_finish_google to save the credentials. '
            . 'The redirect URI that must be registered in your Google Cloud OAuth app: '
            . $this->callbackUrl(),
            [
                'type'       => 'object',
                'properties' => [
                    'client_id'         => ['type' => 'string', 'description' => 'Google OAuth2 client ID (Web application type)'],
                    'client_secret'     => ['type' => 'string', 'description' => 'Google OAuth2 client secret'],
                    'developer_token'   => ['type' => 'string', 'description' => 'Google Ads developer token'],
                    'login_customer_id' => ['type' => 'string', 'description' => 'MCC manager account ID (optional, digits only)'],
                ],
                'required' => ['client_id', 'client_secret', 'developer_token'],
            ],
            [$this, 'startGoogle']
        );

        $server->registerTool(
            'auth_finish_google',
            'Complete Google OAuth2 authorization. Call this after opening the URL from auth_start_google and authorizing in the browser.',
            ['type' => 'object', 'properties' => [], 'required' => []],
            [$this, 'finishGoogle']
        );

        $server->registerTool(
            'auth_start_meta',
            'Begin Meta (Facebook/Instagram) OAuth2 authorization. Returns a URL to open in a browser. '
            . 'After authorizing, call auth_finish_meta to save the credentials. '
            . 'The redirect URI that must be registered in your Meta app → Facebook Login → Valid OAuth Redirect URIs: '
            . $this->callbackUrl(),
            [
                'type'       => 'object',
                'properties' => [
                    'app_id'     => ['type' => 'string', 'description' => 'Meta App ID'],
                    'app_secret' => ['type' => 'string', 'description' => 'Meta App secret'],
                ],
                'required' => ['app_id', 'app_secret'],
            ],
            [$this, 'startMeta']
        );

        $server->registerTool(
            'auth_finish_meta',
            'Complete Meta OAuth2 authorization. Call this after opening the URL from auth_start_meta and authorizing in the browser.',
            ['type' => 'object', 'properties' => [], 'required' => []],
            [$this, 'finishMeta']
        );

        $server->registerTool(
            'auth_status',
            'Show which platforms are currently configured for this user.',
            ['type' => 'object', 'properties' => [], 'required' => []],
            [$this, 'status']
        );
    }

    // ─── Tool handlers ────────────────────────────────────────────────────────

    public function startGoogle(array $args): array
    {
        $clientId   = $args['client_id'];
        $clientSecret = $args['client_secret'];
        $devToken   = $args['developer_token'];
        $mccId      = $args['login_customer_id'] ?? '';

        $toSave = [
            'GOOGLE_ADS_CLIENT_ID'       => $clientId,
            'GOOGLE_ADS_CLIENT_SECRET'   => $clientSecret,
            'GOOGLE_ADS_DEVELOPER_TOKEN' => $devToken,
            'GOOGLE_CLIENT_ID'           => $clientId,
            'GOOGLE_CLIENT_SECRET'       => $clientSecret,
        ];
        if ($mccId !== '') {
            $toSave['GOOGLE_ADS_LOGIN_CUSTOMER_ID'] = $mccId;
        }
        $this->saveEnv($toSave);

        $state = bin2hex(random_bytes(16));
        $this->storePending($state, 'google');

        $scopes = implode(' ', [
            'https://www.googleapis.com/auth/adwords',
            'https://www.googleapis.com/auth/analytics.readonly',
        ]);

        $oauth2  = new OAuth2([
            'clientId'           => $clientId,
            'clientSecret'       => $clientSecret,
            'authorizationUri'   => 'https://accounts.google.com/o/oauth2/v2/auth',
            'redirectUri'        => $this->callbackUrl(),
            'tokenCredentialUri' => CredentialsLoader::TOKEN_CREDENTIAL_URI,
            'scope'              => $scopes,
            'state'              => $state,
        ]);
        $authUrl = (string) $oauth2->buildFullAuthorizationUri([
            'access_type' => 'offline',
            'prompt'      => 'consent',
        ]);

        return [
            'status'       => 'pending',
            'url'          => $authUrl,
            'redirect_uri' => $this->callbackUrl(),
            'message'      => 'Open the URL in your browser and authorize access. '
                . 'Then call auth_finish_google. '
                . 'Make sure "' . $this->callbackUrl() . '" is added as an authorized redirect URI '
                . 'in your Google Cloud OAuth app (Credentials → Web application).',
        ];
    }

    public function finishGoogle(array $args): array
    {
        $result = $this->consumeCode('google');

        if ($result['status'] === 'no_pending') {
            return ['status' => 'error', 'message' => 'No pending Google auth found. Call auth_start_google first.'];
        }
        if ($result['status'] === 'waiting') {
            return ['status' => 'waiting', 'message' => 'Authorization not yet received. Open the URL from auth_start_google in a browser, complete the sign-in, then call auth_finish_google again.'];
        }

        $env = $this->freshEnv();

        $oauth2 = new OAuth2([
            'clientId'           => $env['GOOGLE_ADS_CLIENT_ID'] ?? '',
            'clientSecret'       => $env['GOOGLE_ADS_CLIENT_SECRET'] ?? '',
            'redirectUri'        => $this->callbackUrl(),
            'tokenCredentialUri' => CredentialsLoader::TOKEN_CREDENTIAL_URI,
        ]);
        $oauth2->setCode($result['code']);

        $tokens = $oauth2->fetchAuthToken();

        if (empty($tokens['refresh_token'])) {
            return [
                'status'  => 'error',
                'message' => 'No refresh_token in response. Revoke previous access at '
                    . 'https://myaccount.google.com/permissions then call auth_start_google again.',
            ];
        }

        $this->saveEnv([
            'GOOGLE_ADS_REFRESH_TOKEN' => $tokens['refresh_token'],
            'GOOGLE_REFRESH_TOKEN'     => $tokens['refresh_token'],
        ]);

        return [
            'status'  => 'success',
            'message' => 'Google credentials saved. Google Ads and Analytics are now configured.',
            'refresh_token_preview' => substr($tokens['refresh_token'], 0, 12) . '…',
        ];
    }

    public function startMeta(array $args): array
    {
        $appId     = $args['app_id'];
        $appSecret = $args['app_secret'];

        $this->saveEnv([
            'META_APP_ID'     => $appId,
            'META_APP_SECRET' => $appSecret,
        ]);

        $state   = bin2hex(random_bytes(16));
        $this->storePending($state, 'meta');

        $authUrl = 'https://www.facebook.com/dialog/oauth?' . http_build_query([
            'client_id'     => $appId,
            'redirect_uri'  => $this->callbackUrl(),
            'scope'         => 'ads_read,ads_management,business_management',
            'response_type' => 'code',
            'state'         => $state,
        ]);

        return [
            'status'       => 'pending',
            'url'          => $authUrl,
            'redirect_uri' => $this->callbackUrl(),
            'message'      => 'Open the URL in your browser and authorize access. '
                . 'Then call auth_finish_meta. '
                . 'Make sure "' . $this->callbackUrl() . '" is added to your Meta app → '
                . 'Facebook Login → Valid OAuth Redirect URIs.',
        ];
    }

    public function finishMeta(array $args): array
    {
        $result = $this->consumeCode('meta');

        if ($result['status'] === 'no_pending') {
            return ['status' => 'error', 'message' => 'No pending Meta auth found. Call auth_start_meta first.'];
        }
        if ($result['status'] === 'waiting') {
            return ['status' => 'waiting', 'message' => 'Authorization not yet received. Open the URL from auth_start_meta in a browser, complete the sign-in, then call auth_finish_meta again.'];
        }

        $env    = $this->freshEnv();
        $appId  = $env['META_APP_ID']     ?? '';
        $secret = $env['META_APP_SECRET'] ?? '';

        $ctx = stream_context_create(['http' => ['ignore_errors' => true]]);

        // Step 1: code → short-lived token
        $tokenUrl = 'https://graph.facebook.com/oauth/access_token?' . http_build_query([
            'client_id'     => $appId,
            'client_secret' => $secret,
            'redirect_uri'  => $this->callbackUrl(),
            'code'          => $result['code'],
        ]);
        $resp = json_decode((string) file_get_contents($tokenUrl, false, $ctx), true);

        if (!isset($resp['access_token'])) {
            return ['status' => 'error', 'message' => 'Token exchange failed: ' . json_encode($resp)];
        }

        // Step 2: short-lived → long-lived (~60 days)
        $exchangeUrl = 'https://graph.facebook.com/oauth/access_token?' . http_build_query([
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => $appId,
            'client_secret'     => $secret,
            'fb_exchange_token' => $resp['access_token'],
        ]);
        $exchanged  = json_decode((string) file_get_contents($exchangeUrl, false, $ctx), true);
        $longToken  = $exchanged['access_token'] ?? $resp['access_token'];
        $expiresIn  = isset($exchanged['expires_in'])
            ? round($exchanged['expires_in'] / 86400) . ' days'
            : 'unknown';

        $this->saveEnv(['META_ACCESS_TOKEN' => $longToken]);

        return [
            'status'         => 'success',
            'message'        => 'Meta credentials saved.',
            'token_expires'  => $expiresIn,
            'token_preview'  => substr($longToken, 0, 20) . '…',
        ];
    }

    public function status(array $args): array
    {
        $env = $this->freshEnv();

        $googleOk = !empty($env['GOOGLE_ADS_REFRESH_TOKEN'])
            && !empty($env['GOOGLE_ADS_CLIENT_ID'])
            && !empty($env['GOOGLE_ADS_DEVELOPER_TOKEN']);

        $analyticsOk = !empty($env['GOOGLE_REFRESH_TOKEN'])
            && !empty($env['GOOGLE_CLIENT_ID']);

        $metaOk = !empty($env['META_ACCESS_TOKEN'])
            && !empty($env['META_APP_ID']);

        return [
            'google_ads'        => $googleOk   ? 'configured' : 'not configured — call auth_start_google',
            'google_analytics'  => $analyticsOk ? 'configured' : 'not configured — call auth_start_google',
            'meta_ads'          => $metaOk      ? 'configured' : 'not configured — call auth_start_meta',
        ];
    }
}
