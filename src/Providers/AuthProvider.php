<?php

use Google\Auth\CredentialsLoader;
use Google\Auth\OAuth2;

/**
 * MCP tools for authorizing Google (Ads + Analytics) and Meta (Facebook/Instagram)
 * directly from Claude — no shell access to the server required.
 *
 * Two-step flow for each platform:
 *   1. auth_start_*   → returns an OAuth URL to open in a browser
 *   2. auth_finish_*  → exchanges the code (stored by /oauth/callback) for a token
 *
 * The redirect URI registered in both Google and Meta apps must be:
 *   https://<your-domain>/oauth/callback
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

    // ─── Internals ────────────────────────────────────────────────────────────

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
            return ['status' => 'waiting'];
        }

        $this->db->prepare("DELETE FROM oauth_pending   WHERE state = ?")->execute([$row['state']]);
        $this->db->prepare("DELETE FROM oauth_callbacks WHERE state = ?")->execute([$row['state']]);

        return ['status' => 'ready', 'code' => $row['code']];
    }

    // ─── Tool registration ────────────────────────────────────────────────────

    public function registerTools(McpServer $server): void
    {
        $callbackUrl = $this->callbackUrl();

        $server->registerTool(
            'auth_start_google',
            <<<DESC
            Start Google OAuth2 authorization for Google Ads + Google Analytics 4.
            Returns a URL the user must open in their browser. After completing the
            browser sign-in, call auth_finish_google to save the credentials.

            ── WHAT YOU NEED BEFORE CALLING THIS TOOL ──────────────────────────────

            STEP 1 — Google Cloud project
            ● Go to https://console.cloud.google.com/projectcreate
            ● Create a new project (name it anything, e.g. "Marketing MCP").
              If you already have a suitable project, select it.

            STEP 2 — Enable APIs
            ● APIs & Services → Library
            ● Search "Google Ads API" → click Enable
            ● Search "Google Analytics Data API" → click Enable

            STEP 3 — OAuth consent screen (one-time setup)
            ● APIs & Services → OAuth consent screen
            ● User Type: External
            ● Fill in: App name, User support email, Developer contact email
            ● Scopes page: click "Add or remove scopes" and add:
                https://www.googleapis.com/auth/adwords
                https://www.googleapis.com/auth/analytics.readonly
            ● Test users: add your own Google account email address
            ● Publishing status can stay "Testing" for personal use

            STEP 4 — Create OAuth 2.0 credentials
            ● APIs & Services → Credentials → + Create Credentials → OAuth client ID
            ● Application type: Web application  ← MUST be "Web application", not "Desktop app"
            ● Name: anything (e.g. "Marketing MCP")
            ● Authorized redirect URIs → + Add URI:
                $callbackUrl
            ● Click Create → a popup shows Client ID and Client Secret → copy both

            STEP 5 — Google Ads developer token
            ● Go to https://ads.google.com/aw/apicenter
               (you need a Google Ads account; create a free one if needed)
            ● Copy the developer token shown on this page
            ● Basic Access (test token): only works with Google Ads test accounts
            ● Standard Access: required for real production accounts
               — apply at the same page, approval takes 1–3 business days

            STEP 6 — Manager (MCC) account ID (optional)
            ● Only needed if you use a Google Ads Manager account to manage
              multiple client accounts
            ● Find the 10-digit ID in the top-right corner of Google Ads
              (it looks like 123-456-7890; pass it without dashes: 1234567890)

            ── PARAMETERS ──────────────────────────────────────────────────────────
            client_id         — OAuth Client ID from Step 4
            client_secret     — OAuth Client Secret from Step 4
            developer_token   — Google Ads developer token from Step 5
            login_customer_id — MCC manager account ID from Step 6 (optional)
            DESC,
            [
                'type'       => 'object',
                'properties' => [
                    'client_id'         => ['type' => 'string', 'description' => 'OAuth2 Client ID (from Google Cloud Console → Credentials → Web application)'],
                    'client_secret'     => ['type' => 'string', 'description' => 'OAuth2 Client Secret (same location as client_id)'],
                    'developer_token'   => ['type' => 'string', 'description' => 'Google Ads developer token (from https://ads.google.com/aw/apicenter)'],
                    'login_customer_id' => ['type' => 'string', 'description' => 'Manager (MCC) account ID — digits only, no dashes (optional)'],
                ],
                'required' => ['client_id', 'client_secret', 'developer_token'],
            ],
            [$this, 'startGoogle']
        );

        $server->registerTool(
            'auth_finish_google',
            <<<DESC
            Complete Google OAuth2 authorization.

            Call this AFTER:
            1. You called auth_start_google and received a URL
            2. You opened that URL in a browser and clicked "Allow"
            3. The browser showed "Authorized! You can close this tab."

            If the browser showed an error instead:
            ● "redirect_uri_mismatch" — the redirect URI in your Google Cloud Console
              OAuth app does not match exactly. Go to APIs & Services → Credentials →
              edit your OAuth client → check that Authorized redirect URIs contains:
              $callbackUrl
            ● "access_denied" — you clicked Deny or your account isn't added as a
              Test User. Add your email under OAuth consent screen → Test users.

            If auth_finish_google returns "waiting", the browser step hasn't completed
            yet. Complete the sign-in first, then call this tool again.
            DESC,
            ['type' => 'object', 'properties' => new stdClass(), 'required' => []],
            [$this, 'finishGoogle']
        );

        $server->registerTool(
            'auth_start_meta',
            <<<DESC
            Start Meta (Facebook / Instagram) OAuth2 authorization for Meta Ads.
            Returns a URL the user must open in their browser. After completing the
            browser sign-in, call auth_finish_meta to save the credentials.

            ── WHAT YOU NEED BEFORE CALLING THIS TOOL ──────────────────────────────

            STEP 1 — Create a Meta developer app
            ● Go to https://developers.facebook.com/apps/create
            ● Select app type: Business
            ● Fill in App name (e.g. "Marketing MCP") and Contact email
            ● Click Create App

            STEP 2 — Add the Marketing API product
            ● On the app dashboard, scroll to "Add products to your app"
            ● Find "Marketing API" → click Set Up
               This enables the ads_read, ads_management, and
               business_management permission scopes.

            STEP 3 — Add Facebook Login and set the redirect URI
            ● Add Products → Facebook Login → click Set Up → choose Web
            ● In the left sidebar: Facebook Login → Settings
            ● Valid OAuth Redirect URIs → add:
                $callbackUrl
            ● Click Save Changes

            STEP 4 — Get App ID and App Secret
            ● Settings → Basic (left sidebar)
            ● Copy the App ID (shown at the top)
            ● Click "Show" next to App Secret → enter your Facebook password → copy it

            STEP 5 — App mode
            ● Development mode: you can only access ad accounts where you are
              an admin AND your Facebook account is listed as a Developer or Tester
              in this app. Fine for personal use.
            ● Live mode: required to access other users' accounts. ads_management
              permission requires Meta App Review before going live (takes 5–10 days).
              For managing only your own accounts, Development mode is sufficient.

            ── PARAMETERS ──────────────────────────────────────────────────────────
            app_id     — App ID from Step 4
            app_secret — App Secret from Step 4
            DESC,
            [
                'type'       => 'object',
                'properties' => [
                    'app_id'     => ['type' => 'string', 'description' => 'Meta App ID (from developers.facebook.com → Settings → Basic)'],
                    'app_secret' => ['type' => 'string', 'description' => 'Meta App Secret (same page, click Show to reveal)'],
                ],
                'required' => ['app_id', 'app_secret'],
            ],
            [$this, 'startMeta']
        );

        $server->registerTool(
            'auth_finish_meta',
            <<<DESC
            Complete Meta OAuth2 authorization.

            Call this AFTER:
            1. You called auth_start_meta and received a URL
            2. You opened that URL in a browser and clicked "Continue as <your name>"
               then "Continue" on the permissions screen
            3. The browser showed "Authorized! You can close this tab."

            The token obtained is a long-lived user token (~60 days). Meta does not
            issue refresh tokens — you will need to re-authorize when it expires.
            You can check the expiry with auth_status.

            If the browser showed an error:
            ● "URL Blocked" — the redirect URI isn't registered. Go to your Meta app →
              Facebook Login → Settings → Valid OAuth Redirect URIs and add:
              $callbackUrl
            ● "Permission error" — make sure Marketing API is added to your app
              (app dashboard → Add Products → Marketing API → Set Up).

            If auth_finish_meta returns "waiting", the browser step hasn't completed
            yet. Complete the sign-in first, then call this tool again.
            DESC,
            ['type' => 'object', 'properties' => new stdClass(), 'required' => []],
            [$this, 'finishMeta']
        );

        $server->registerTool(
            'auth_status',
            'Show which platforms are currently authorized for this user, '
            . 'which credentials are missing, and what to do next.',
            ['type' => 'object', 'properties' => new stdClass(), 'required' => []],
            [$this, 'status']
        );
    }

    // ─── Handlers ─────────────────────────────────────────────────────────────

    public function startGoogle(array $args): array
    {
        $clientId     = $args['client_id'];
        $clientSecret = $args['client_secret'];
        $devToken     = $args['developer_token'];
        $mccId        = trim($args['login_customer_id'] ?? '');

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

        $oauth2  = new OAuth2([
            'clientId'           => $clientId,
            'clientSecret'       => $clientSecret,
            'authorizationUri'   => 'https://accounts.google.com/o/oauth2/v2/auth',
            'redirectUri'        => $this->callbackUrl(),
            'tokenCredentialUri' => CredentialsLoader::TOKEN_CREDENTIAL_URI,
            'scope'              => implode(' ', [
                'https://www.googleapis.com/auth/adwords',
                'https://www.googleapis.com/auth/analytics.readonly',
            ]),
            'state' => $state,
        ]);
        $authUrl = (string) $oauth2->buildFullAuthorizationUri([
            'access_type' => 'offline',
            'prompt'      => 'consent',
        ]);

        return [
            'status'            => 'pending',
            'auth_url'          => $authUrl,
            'redirect_uri_used' => $this->callbackUrl(),
            'next_step'         => 'Open auth_url in your browser → sign in with your Google account → click Allow. '
                . 'Then call auth_finish_google.',
            'if_you_see_redirect_uri_mismatch' =>
                'The URI "' . $this->callbackUrl() . '" must be added to your OAuth app. '
                . 'Go to console.cloud.google.com → APIs & Services → Credentials → '
                . 'edit your OAuth client → Authorized redirect URIs → add the URI above.',
        ];
    }

    public function finishGoogle(array $args): array
    {
        $result = $this->consumeCode('google');

        if ($result['status'] === 'no_pending') {
            return [
                'status'    => 'error',
                'message'   => 'No pending Google authorization found.',
                'next_step' => 'Call auth_start_google first with your client_id, client_secret, and developer_token.',
            ];
        }

        if ($result['status'] === 'waiting') {
            return [
                'status'    => 'waiting',
                'message'   => 'The browser authorization has not been completed yet.',
                'next_step' => 'Open the URL returned by auth_start_google in a browser, '
                    . 'sign in with your Google account, click Allow, '
                    . 'wait for the "Authorized!" page, then call auth_finish_google again.',
            ];
        }

        $env    = $this->freshEnv();
        $oauth2 = new OAuth2([
            'clientId'           => $env['GOOGLE_ADS_CLIENT_ID']     ?? '',
            'clientSecret'       => $env['GOOGLE_ADS_CLIENT_SECRET']  ?? '',
            'redirectUri'        => $this->callbackUrl(),
            'tokenCredentialUri' => CredentialsLoader::TOKEN_CREDENTIAL_URI,
        ]);
        $oauth2->setCode($result['code']);

        $tokens = $oauth2->fetchAuthToken();

        if (empty($tokens['refresh_token'])) {
            return [
                'status'    => 'error',
                'message'   => 'Google did not return a refresh_token.',
                'next_step' => 'This usually happens when you previously authorized this app and didn\'t revoke it. '
                    . 'Go to https://myaccount.google.com/permissions → find your app → Remove access. '
                    . 'Then call auth_start_google again.',
            ];
        }

        $this->saveEnv([
            'GOOGLE_ADS_REFRESH_TOKEN' => $tokens['refresh_token'],
            'GOOGLE_REFRESH_TOKEN'     => $tokens['refresh_token'],
        ]);

        return [
            'status'   => 'success',
            'message'  => 'Google Ads and Analytics credentials saved successfully.',
            'what_you_can_do_now' => [
                'Google Ads' => 'Call google_ads_list_customers to see your ad accounts.',
                'Analytics'  => 'Call ga4_get_audience_overview with your GA4 property_id.',
            ],
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

        $state = bin2hex(random_bytes(16));
        $this->storePending($state, 'meta');

        $authUrl = 'https://www.facebook.com/dialog/oauth?' . http_build_query([
            'client_id'     => $appId,
            'redirect_uri'  => $this->callbackUrl(),
            'scope'         => 'ads_read,ads_management,business_management',
            'response_type' => 'code',
            'state'         => $state,
        ]);

        return [
            'status'            => 'pending',
            'auth_url'          => $authUrl,
            'redirect_uri_used' => $this->callbackUrl(),
            'next_step'         => 'Open auth_url in your browser → click "Continue as <your name>" → '
                . 'click Continue on the permissions screen. '
                . 'Then call auth_finish_meta.',
            'if_you_see_url_blocked' =>
                'The URI "' . $this->callbackUrl() . '" must be registered in your Meta app. '
                . 'Go to developers.facebook.com → your app → Facebook Login → Settings → '
                . 'Valid OAuth Redirect URIs → add the URI above → Save Changes.',
        ];
    }

    public function finishMeta(array $args): array
    {
        $result = $this->consumeCode('meta');

        if ($result['status'] === 'no_pending') {
            return [
                'status'    => 'error',
                'message'   => 'No pending Meta authorization found.',
                'next_step' => 'Call auth_start_meta first with your app_id and app_secret.',
            ];
        }

        if ($result['status'] === 'waiting') {
            return [
                'status'    => 'waiting',
                'message'   => 'The browser authorization has not been completed yet.',
                'next_step' => 'Open the URL returned by auth_start_meta in a browser, '
                    . 'click "Continue as <your name>", click Continue on the permissions screen, '
                    . 'wait for the "Authorized!" page, then call auth_finish_meta again.',
            ];
        }

        $env    = $this->freshEnv();
        $appId  = $env['META_APP_ID']     ?? '';
        $secret = $env['META_APP_SECRET'] ?? '';
        $ctx    = stream_context_create(['http' => ['ignore_errors' => true]]);

        // Step 1: code → short-lived token
        $resp = json_decode((string) file_get_contents(
            'https://graph.facebook.com/oauth/access_token?' . http_build_query([
                'client_id'     => $appId,
                'client_secret' => $secret,
                'redirect_uri'  => $this->callbackUrl(),
                'code'          => $result['code'],
            ]),
            false, $ctx
        ), true);

        if (!isset($resp['access_token'])) {
            return [
                'status'  => 'error',
                'message' => 'Token exchange failed: ' . json_encode($resp),
                'next_step' => 'Check that app_id and app_secret are correct, '
                    . 'and that the redirect URI is registered in your Meta app.',
            ];
        }

        // Step 2: short-lived → long-lived (~60 days)
        $exchanged = json_decode((string) file_get_contents(
            'https://graph.facebook.com/oauth/access_token?' . http_build_query([
                'grant_type'        => 'fb_exchange_token',
                'client_id'         => $appId,
                'client_secret'     => $secret,
                'fb_exchange_token' => $resp['access_token'],
            ]),
            false, $ctx
        ), true);

        $longToken = $exchanged['access_token'] ?? $resp['access_token'];
        $expiresIn = isset($exchanged['expires_in'])
            ? round($exchanged['expires_in'] / 86400) . ' days'
            : 'unknown';

        $this->saveEnv(['META_ACCESS_TOKEN' => $longToken]);

        return [
            'status'              => 'success',
            'message'             => 'Meta credentials saved successfully.',
            'token_expires_in'    => $expiresIn,
            'token_preview'       => substr($longToken, 0, 20) . '…',
            'what_you_can_do_now' => [
                'Meta Ads' => 'Call meta_get_ad_accounts to see your ad accounts.',
            ],
            'note' => 'Meta long-lived tokens expire in ~60 days. '
                . 'Call auth_start_meta + auth_finish_meta again when it expires.',
        ];
    }

    public function status(array $args): array
    {
        $env = $this->freshEnv();

        $hasGoogleOAuth   = !empty($env['GOOGLE_ADS_CLIENT_ID']) && !empty($env['GOOGLE_ADS_CLIENT_SECRET']);
        $hasGoogleToken   = !empty($env['GOOGLE_ADS_REFRESH_TOKEN']);
        $hasDevToken      = !empty($env['GOOGLE_ADS_DEVELOPER_TOKEN']);
        $hasAnalytics     = !empty($env['GOOGLE_REFRESH_TOKEN']) && !empty($env['GOOGLE_CLIENT_ID']);
        $hasMetaApp       = !empty($env['META_APP_ID']) && !empty($env['META_APP_SECRET']);
        $hasMetaToken     = !empty($env['META_ACCESS_TOKEN']);

        $googleAdsOk     = $hasGoogleOAuth && $hasGoogleToken && $hasDevToken;
        $analyticsOk     = $hasAnalytics;
        $metaOk          = $hasMetaApp && $hasMetaToken;

        $result = [
            'google_ads' => [
                'status' => $googleAdsOk ? '✓ configured' : '✗ not configured',
            ],
            'google_analytics' => [
                'status' => $analyticsOk ? '✓ configured' : '✗ not configured',
            ],
            'meta_ads' => [
                'status' => $metaOk ? '✓ configured' : '✗ not configured',
            ],
        ];

        if (!$googleAdsOk || !$analyticsOk) {
            $missing = [];
            if (!$hasGoogleOAuth) {
                $missing[] = 'OAuth credentials (client_id, client_secret) — create them at '
                    . 'console.cloud.google.com → APIs & Services → Credentials';
            }
            if (!$hasDevToken) {
                $missing[] = 'Google Ads developer token — get it at ads.google.com/aw/apicenter';
            }
            if (!$hasGoogleToken) {
                $missing[] = 'refresh_token — complete the OAuth flow';
            }
            $result['google_ads']['missing']   = $missing;
            $result['google_analytics']['missing'] = $missing;
            $result['google_ads']['next_step'] =
                'Call auth_start_google with your client_id, client_secret, and developer_token. '
                . 'The tool description contains step-by-step instructions for getting these values.';
        }

        if (!$metaOk) {
            $missing = [];
            if (!$hasMetaApp) {
                $missing[] = 'App ID and App Secret — get them at '
                    . 'developers.facebook.com → your app → Settings → Basic';
            }
            if (!$hasMetaToken) {
                $missing[] = 'access_token — complete the OAuth flow';
            }
            $result['meta_ads']['missing']   = $missing;
            $result['meta_ads']['next_step'] =
                'Call auth_start_meta with your app_id and app_secret. '
                . 'The tool description contains step-by-step instructions for creating a Meta developer app.';
        }

        if ($googleAdsOk && $analyticsOk && $metaOk) {
            $result['summary'] = 'All platforms are configured. '
                . 'Use google_ads_list_customers and meta_get_ad_accounts to discover account IDs.';
        }

        return $result;
    }
}
