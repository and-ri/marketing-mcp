<?php

/**
 * Bing Webmaster Tools API (JSON endpoint).
 * Auth: static API key — Bing Webmaster → Settings (gear) → API access → API Key.
 * https://learn.microsoft.com/en-us/bingwebmaster/getting-access
 */
class BingWebmasterProvider
{
    private const BASE = 'https://ssl.bing.com/webmaster/api.svc/json/';

    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config ?: $_ENV;
    }

    private function apiKey(): string
    {
        $key = $this->config['BING_WEBMASTER_API_KEY'] ?? '';
        if ($key === '') {
            throw new \RuntimeException(
                'BING_WEBMASTER_API_KEY is not configured. Generate it in Bing Webmaster Tools → Settings → API access → API Key, then call auth_set_bing_webmaster.'
            );
        }
        return $key;
    }

    protected function get(string $method, array $query = []): mixed
    {
        $url = self::BASE . $method . '?' . http_build_query(['apikey' => $this->apiKey()] + $query);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_TIMEOUT        => 60,
        ]);
        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException("Bing Webmaster API request failed: $err");
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("Bing Webmaster API returned HTTP $status: $body");
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new \RuntimeException("Bing Webmaster API returned invalid JSON: $body");
        }
        // Responses are wrapped in {"d": ...}
        return self::normalizeDates(array_key_exists('d', $data) ? $data['d'] : $data);
    }

    /** Converts WCF "/Date(1700000000000-0800)/" strings to ISO dates, recursively; drops "__type". */
    private static function normalizeDates(mixed $value): mixed
    {
        if (is_array($value)) {
            unset($value['__type']);
            return array_map([self::class, 'normalizeDates'], $value);
        }
        if (is_string($value) && preg_match('#^/Date\((-?\d+)([+-]\d{4})?\)/$#', $value, $m)) {
            return gmdate('Y-m-d', intdiv((int) $m[1], 1000));
        }
        return $value;
    }

    private static function site(array $args): string
    {
        $site = trim((string) ($args['site_url'] ?? ''));
        if ($site === '') {
            throw new \InvalidArgumentException('site_url is required (as listed by bing_list_sites, e.g. "https://example.com/").');
        }
        return $site;
    }

    /**
     * Client-side date filter + sort + limit for the stats endpoints, which return the full history.
     */
    private static function shapeRows(array $rows, array $args, ?string $sortBy = null): array
    {
        $start = $args['start_date'] ?? null;
        $end   = $args['end_date'] ?? null;
        if ($start || $end) {
            $rows = array_values(array_filter($rows, function ($r) use ($start, $end) {
                $d = $r['Date'] ?? null;
                if (!is_string($d)) {
                    return true;
                }
                return (!$start || $d >= $start) && (!$end || $d <= $end);
            }));
        }
        if ($sortBy !== null) {
            usort($rows, fn($a, $b) => ($b[$sortBy] ?? 0) <=> ($a[$sortBy] ?? 0));
        }
        $total = count($rows);
        $limit = (int) ($args['limit'] ?? 0);
        if ($limit > 0) {
            $rows = array_slice($rows, 0, $limit);
        }
        return ['rows' => $rows, 'total' => $total, 'returned' => count($rows)];
    }

    public function registerTools(McpServer $server): void
    {
        $siteProp  = ['type' => 'string', 'description' => 'Site URL exactly as listed by bing_list_sites, e.g. "https://example.com/"'];
        $dateProps = [
            'start_date' => ['type' => 'string', 'description' => 'Optional: keep rows on/after this date (YYYY-MM-DD)'],
            'end_date'   => ['type' => 'string', 'description' => 'Optional: keep rows on/before this date (YYYY-MM-DD)'],
        ];
        $limitProp = ['limit' => ['type' => 'integer', 'description' => 'Max rows to return (0 = all)', 'default' => 100]];

        $server->registerTool(
            'bing_list_sites',
            'List sites verified in Bing Webmaster Tools for this API key. Use the returned Url as site_url for other bing_* tools.',
            ['type' => 'object', 'properties' => new stdClass(), 'required' => []],
            [$this, 'listSites']
        );

        $server->registerTool(
            'bing_get_traffic_stats',
            'Get daily Bing organic traffic for a site: impressions and clicks per day (Bing + DuckDuckGo/Yahoo syndicated search). Covers roughly the last 6 months.',
            [
                'type'       => 'object',
                'properties' => ['site_url' => $siteProp] + $dateProps,
                'required'   => ['site_url'],
            ],
            [$this, 'getTrafficStats']
        );

        $server->registerTool(
            'bing_get_query_stats',
            'Get top Bing search queries for a site with impressions, clicks, average click position and average impression position. Rows are weekly; sorted by impressions.',
            [
                'type'       => 'object',
                'properties' => ['site_url' => $siteProp] + $dateProps + $limitProp,
                'required'   => ['site_url'],
            ],
            [$this, 'getQueryStats']
        );

        $server->registerTool(
            'bing_get_page_stats',
            'Get top pages of a site in Bing search with impressions, clicks and average positions. Rows are weekly; sorted by impressions.',
            [
                'type'       => 'object',
                'properties' => ['site_url' => $siteProp] + $dateProps + $limitProp,
                'required'   => ['site_url'],
            ],
            [$this, 'getPageStats']
        );

        $server->registerTool(
            'bing_get_query_page_stats',
            'Get pages that rank in Bing for one specific search query (query → pages drill-down).',
            [
                'type'       => 'object',
                'properties' => ['site_url' => $siteProp, 'query' => ['type' => 'string', 'description' => 'Search query']] + $dateProps + $limitProp,
                'required'   => ['site_url', 'query'],
            ],
            [$this, 'getQueryPageStats']
        );

        $server->registerTool(
            'bing_get_page_query_stats',
            'Get search queries that drive Bing traffic to one specific page (page → queries drill-down).',
            [
                'type'       => 'object',
                'properties' => ['site_url' => $siteProp, 'page' => ['type' => 'string', 'description' => 'Full page URL']] + $dateProps + $limitProp,
                'required'   => ['site_url', 'page'],
            ],
            [$this, 'getPageQueryStats']
        );

        $server->registerTool(
            'bing_get_crawl_stats',
            'Get daily Bingbot crawl stats for a site: pages crawled, crawl errors, in-index count, in-links, blocked by robots.txt, HTTP 2xx/3xx/4xx/5xx codes, DNS/connection failures.',
            [
                'type'       => 'object',
                'properties' => ['site_url' => $siteProp] + $dateProps,
                'required'   => ['site_url'],
            ],
            [$this, 'getCrawlStats']
        );

        $server->registerTool(
            'bing_get_crawl_issues',
            'Get URLs with crawl issues detected by Bingbot (HTTP errors, blocked by robots.txt, DNS/timeouts, malware, etc.).',
            [
                'type'       => 'object',
                'properties' => ['site_url' => $siteProp] + $limitProp,
                'required'   => ['site_url'],
            ],
            [$this, 'getCrawlIssues']
        );

        $server->registerTool(
            'bing_get_link_counts',
            'Get inbound backlink counts per target page of the site, as seen by Bing. Paginated.',
            [
                'type'       => 'object',
                'properties' => [
                    'site_url' => $siteProp,
                    'page'     => ['type' => 'integer', 'description' => 'Page index starting from 0', 'default' => 0],
                ],
                'required' => ['site_url'],
            ],
            [$this, 'getLinkCounts']
        );

        $server->registerTool(
            'bing_get_url_info',
            'Get Bing index details for a single URL: HTTP status, last crawled date, discovery date, document size, anchor count, and whether it is indexed.',
            [
                'type'       => 'object',
                'properties' => [
                    'site_url' => $siteProp,
                    'url'      => ['type' => 'string', 'description' => 'Full URL to inspect (must belong to site_url)'],
                ],
                'required' => ['site_url', 'url'],
            ],
            [$this, 'getUrlInfo']
        );

        $server->registerTool(
            'bing_get_sitemaps',
            'List sitemaps/feeds submitted to Bing Webmaster Tools for a site, with status, last crawled date and URL counts.',
            [
                'type'       => 'object',
                'properties' => ['site_url' => $siteProp],
                'required'   => ['site_url'],
            ],
            [$this, 'getSitemaps']
        );

        $server->registerTool(
            'bing_get_keyword_stats',
            'Bing keyword research: weekly impression volume for a keyword in a given market (not limited to your site). Useful for estimating demand on Bing.',
            [
                'type'       => 'object',
                'properties' => [
                    'query'    => ['type' => 'string', 'description' => 'Keyword to research'],
                    'country'  => ['type' => 'string', 'description' => 'Country code, e.g. "us", "ua", "gb"', 'default' => 'us'],
                    'language' => ['type' => 'string', 'description' => 'Language-culture code, e.g. "en-US", "uk-UA"', 'default' => 'en-US'],
                ],
                'required' => ['query'],
            ],
            [$this, 'getKeywordStats']
        );

        $server->registerTool(
            'bing_get_url_submission_quota',
            'Get the remaining daily and monthly URL submission quota for a site in Bing Webmaster Tools.',
            [
                'type'       => 'object',
                'properties' => ['site_url' => $siteProp],
                'required'   => ['site_url'],
            ],
            [$this, 'getUrlSubmissionQuota']
        );
    }

    public function listSites(array $args): array
    {
        return ['sites' => $this->get('GetUserSites') ?? []];
    }

    public function getTrafficStats(array $args): array
    {
        $rows = $this->get('GetRankAndTrafficStats', ['siteUrl' => self::site($args)]) ?? [];
        return self::shapeRows($rows, $args);
    }

    public function getQueryStats(array $args): array
    {
        $rows = $this->get('GetQueryStats', ['siteUrl' => self::site($args)]) ?? [];
        return self::shapeRows($rows, $args + ['limit' => 100], 'Impressions');
    }

    public function getPageStats(array $args): array
    {
        $rows = $this->get('GetPageStats', ['siteUrl' => self::site($args)]) ?? [];
        return self::shapeRows($rows, $args + ['limit' => 100], 'Impressions');
    }

    public function getQueryPageStats(array $args): array
    {
        $rows = $this->get('GetQueryPageStats', [
            'siteUrl' => self::site($args),
            'query'   => (string) $args['query'],
        ]) ?? [];
        return self::shapeRows($rows, $args + ['limit' => 100], 'Impressions');
    }

    public function getPageQueryStats(array $args): array
    {
        $rows = $this->get('GetPageQueryStats', [
            'siteUrl' => self::site($args),
            'page'    => (string) $args['page'],
        ]) ?? [];
        return self::shapeRows($rows, $args + ['limit' => 100], 'Impressions');
    }

    public function getCrawlStats(array $args): array
    {
        $rows = $this->get('GetCrawlStats', ['siteUrl' => self::site($args)]) ?? [];
        return self::shapeRows($rows, $args);
    }

    public function getCrawlIssues(array $args): array
    {
        $rows = $this->get('GetCrawlIssues', ['siteUrl' => self::site($args)]) ?? [];
        return self::shapeRows($rows, $args + ['limit' => 100]);
    }

    public function getLinkCounts(array $args): array
    {
        return $this->get('GetLinkCounts', [
            'siteUrl' => self::site($args),
            'page'    => max((int) ($args['page'] ?? 0), 0),
        ]) ?? [];
    }

    public function getUrlInfo(array $args): array
    {
        return $this->get('GetUrlInfo', [
            'siteUrl' => self::site($args),
            'url'     => (string) $args['url'],
        ]) ?? [];
    }

    public function getSitemaps(array $args): array
    {
        return ['sitemaps' => $this->get('GetFeeds', ['siteUrl' => self::site($args)]) ?? []];
    }

    public function getKeywordStats(array $args): array
    {
        $rows = $this->get('GetKeywordStats', [
            'q'        => (string) $args['query'],
            'country'  => (string) ($args['country'] ?? 'us'),
            'language' => (string) ($args['language'] ?? 'en-US'),
        ]) ?? [];
        return ['query' => $args['query'], 'rows' => $rows];
    }

    public function getUrlSubmissionQuota(array $args): array
    {
        return $this->get('GetUrlSubmissionQuota', ['siteUrl' => self::site($args)]) ?? [];
    }
}
