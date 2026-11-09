<?php

use Google\Auth\Credentials\UserRefreshCredentials;

/**
 * Google Search Console (Search Analytics, Sitemaps, URL Inspection).
 * Reuses the Google OAuth client from auth_start_google — the refresh token
 * must include the https://www.googleapis.com/auth/webmasters.readonly scope.
 */
class SearchConsoleProvider
{
    private const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

    private array $config;
    private ?string $accessToken = null;

    public function __construct(array $config = [])
    {
        $this->config = $config ?: $_ENV;
    }

    private function token(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        foreach (['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET', 'GOOGLE_REFRESH_TOKEN'] as $key) {
            if (empty($this->config[$key])) {
                throw new \RuntimeException(
                    "$key is not configured. Call auth_start_google + auth_finish_google to authorize Google (includes Search Console)."
                );
            }
        }

        $credentials = new UserRefreshCredentials(self::SCOPE, [
            'client_id'     => $this->config['GOOGLE_CLIENT_ID'],
            'client_secret' => $this->config['GOOGLE_CLIENT_SECRET'],
            'refresh_token' => $this->config['GOOGLE_REFRESH_TOKEN'],
        ]);
        $token = $credentials->fetchAuthToken();
        if (empty($token['access_token'])) {
            throw new \RuntimeException('Failed to obtain Google access token: ' . json_encode($token));
        }
        return $this->accessToken = $token['access_token'];
    }

    private function request(string $method, string $url, ?array $body = null): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->token(),
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 60,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        }
        curl_setopt_array($ch, $opts);
        $resp   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            throw new \RuntimeException("Search Console API request failed: $err");
        }
        if ($status === 403 && str_contains($resp, 'insufficient')) {
            throw new \RuntimeException(
                'Search Console API: the saved Google token lacks the webmasters.readonly scope. '
                . 'Re-run auth_start_google + auth_finish_google to grant Search Console access.'
            );
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("Search Console API returned HTTP $status: $resp");
        }

        $data = json_decode($resp, true);
        return is_array($data) ? $data : [];
    }

    private static function site(array $args): string
    {
        $site = trim((string) ($args['site_url'] ?? ''));
        if ($site === '') {
            throw new \InvalidArgumentException('site_url is required (e.g. "https://example.com/" or "sc-domain:example.com").');
        }
        return $site;
    }

    private static function siteBase(string $site): string
    {
        return 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($site);
    }

    public function registerTools(McpServer $server): void
    {
        $siteProp = [
            'type'        => 'string',
            'description' => 'Search Console property exactly as listed by gsc_list_sites: "https://example.com/" (URL-prefix) or "sc-domain:example.com" (domain property)',
        ];

        $server->registerTool(
            'gsc_list_sites',
            'List Google Search Console properties available to the authorized Google account, with permission level. Use this to find the site_url for other gsc_* tools.',
            ['type' => 'object', 'properties' => new stdClass(), 'required' => []],
            [$this, 'listSites']
        );

        $server->registerTool(
            'gsc_search_analytics',
            'Query Google Search Console search performance: clicks, impressions, CTR, and average position, broken down by query, page, country, device, date, or searchAppearance. Data is available with ~2–3 days delay and for up to 16 months back.',
            [
                'type'       => 'object',
                'properties' => [
                    'site_url'    => $siteProp,
                    'start_date'  => ['type' => 'string', 'description' => 'Start date YYYY-MM-DD'],
                    'end_date'    => ['type' => 'string', 'description' => 'End date YYYY-MM-DD'],
                    'dimensions'  => [
                        'type'        => 'array',
                        'items'       => ['type' => 'string', 'enum' => ['query', 'page', 'country', 'device', 'date', 'searchAppearance']],
                        'description' => 'Breakdown dimensions, e.g. ["query"] or ["page","query"]. Default ["query"].',
                    ],
                    'search_type' => ['type' => 'string', 'enum' => ['web', 'image', 'video', 'news', 'discover', 'googleNews'], 'default' => 'web'],
                    'filters'     => [
                        'type'        => 'array',
                        'description' => 'Optional filters (AND-combined). Each: {dimension, operator, expression}. operator: equals, notEquals, contains, notContains, includingRegex, excludingRegex.',
                        'items'       => [
                            'type'       => 'object',
                            'properties' => [
                                'dimension'  => ['type' => 'string'],
                                'operator'   => ['type' => 'string'],
                                'expression' => ['type' => 'string'],
                            ],
                            'required' => ['dimension', 'expression'],
                        ],
                    ],
                    'row_limit'   => ['type' => 'integer', 'description' => 'Max rows (1–25000)', 'default' => 1000],
                    'start_row'   => ['type' => 'integer', 'description' => 'Pagination offset', 'default' => 0],
                    'data_state'  => ['type' => 'string', 'enum' => ['final', 'all'], 'description' => '"all" includes fresh (not finalized) data', 'default' => 'final'],
                ],
                'required' => ['site_url', 'start_date', 'end_date'],
            ],
            [$this, 'searchAnalytics']
        );

        $server->registerTool(
            'gsc_list_sitemaps',
            'List sitemaps submitted to Google Search Console for a property, with last submitted/downloaded dates, warnings, errors, and indexed URL counts.',
            [
                'type'       => 'object',
                'properties' => ['site_url' => $siteProp],
                'required'   => ['site_url'],
            ],
            [$this, 'listSitemaps']
        );

        $server->registerTool(
            'gsc_inspect_url',
            'Inspect a single URL in Google Search Console (URL Inspection API): index status, coverage state, last crawl time, Google-selected canonical, robots/indexing state, mobile usability and rich results. Quota: ~2000 inspections/day per property.',
            [
                'type'       => 'object',
                'properties' => [
                    'site_url'       => $siteProp,
                    'inspection_url' => ['type' => 'string', 'description' => 'Fully-qualified URL to inspect; must belong to site_url'],
                    'language_code'  => ['type' => 'string', 'description' => 'Language for translated issue messages, e.g. "en-US" or "uk"', 'default' => 'en-US'],
                ],
                'required' => ['site_url', 'inspection_url'],
            ],
            [$this, 'inspectUrl']
        );
    }

    public function listSites(array $args): array
    {
        $data = $this->request('GET', 'https://www.googleapis.com/webmasters/v3/sites');
        return ['sites' => $data['siteEntry'] ?? []];
    }

    public function searchAnalytics(array $args): array
    {
        $site = self::site($args);

        $body = [
            'startDate'  => (string) $args['start_date'],
            'endDate'    => (string) $args['end_date'],
            'dimensions' => $args['dimensions'] ?? ['query'],
            'type'       => $args['search_type'] ?? 'web',
            'rowLimit'   => min(max((int) ($args['row_limit'] ?? 1000), 1), 25000),
            'startRow'   => max((int) ($args['start_row'] ?? 0), 0),
            'dataState'  => $args['data_state'] ?? 'final',
        ];

        if (!empty($args['filters'])) {
            $body['dimensionFilterGroups'] = [[
                'groupType' => 'and',
                'filters'   => array_map(fn(array $f) => [
                    'dimension'  => $f['dimension'],
                    'operator'   => $f['operator'] ?? 'equals',
                    'expression' => $f['expression'],
                ], $args['filters']),
            ]];
        }

        $data = $this->request('POST', self::siteBase($site) . '/searchAnalytics/query', $body);

        $rows = [];
        foreach ($data['rows'] ?? [] as $row) {
            $entry = [];
            foreach ($body['dimensions'] as $i => $dim) {
                $entry[$dim] = $row['keys'][$i] ?? null;
            }
            $entry['clicks']      = $row['clicks'] ?? 0;
            $entry['impressions'] = $row['impressions'] ?? 0;
            $entry['ctr']         = round(($row['ctr'] ?? 0) * 100, 2);
            $entry['position']    = round($row['position'] ?? 0, 2);
            $rows[] = $entry;
        }

        return [
            'site_url'         => $site,
            'rows'             => $rows,
            'total'            => count($rows),
            'ctr_unit'         => 'percent',
            'aggregation_type' => $data['responseAggregationType'] ?? null,
        ];
    }

    public function listSitemaps(array $args): array
    {
        $site = self::site($args);
        $data = $this->request('GET', self::siteBase($site) . '/sitemaps');
        return ['site_url' => $site, 'sitemaps' => $data['sitemap'] ?? []];
    }

    public function inspectUrl(array $args): array
    {
        $site = self::site($args);
        $url  = trim((string) ($args['inspection_url'] ?? ''));
        if ($url === '') {
            throw new \InvalidArgumentException('inspection_url is required.');
        }

        $data = $this->request('POST', 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect', [
            'inspectionUrl' => $url,
            'siteUrl'       => $site,
            'languageCode'  => $args['language_code'] ?? 'en-US',
        ]);

        return $data['inspectionResult'] ?? $data;
    }
}
