<?php

/**
 * SerpBear — self-hosted keyword rank tracker.
 * Auth: Bearer APIKEY (the APIKEY value from the SerpBear instance .env).
 * https://docs.serpbear.com/miscellaneous/api-documentation/
 */
class SerpBearProvider
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config ?: $_ENV;
    }

    private function baseUrl(): string
    {
        $url = $this->config['SERPBEAR_BASE_URL'] ?? '';
        if ($url === '') {
            throw new \RuntimeException(
                'SERPBEAR_BASE_URL is not configured (e.g. https://serpbear.example.com). Call auth_set_serpbear.'
            );
        }
        return rtrim($url, '/');
    }

    private function apiKey(): string
    {
        $key = $this->config['SERPBEAR_API_KEY'] ?? '';
        if ($key === '') {
            throw new \RuntimeException(
                'SERPBEAR_API_KEY is not configured. It is the APIKEY value from your SerpBear .env. Call auth_set_serpbear.'
            );
        }
        return $key;
    }

    protected function request(string $method, string $path, array $query = []): array
    {
        $url = $this->baseUrl() . $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->apiKey(),
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 120,
        ]);
        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException("SerpBear API request failed: $err");
        }
        if ($status === 401) {
            throw new \RuntimeException('SerpBear API: unauthorized — check SERPBEAR_API_KEY (APIKEY in the SerpBear .env).');
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("SerpBear API returned HTTP $status: $body");
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new \RuntimeException("SerpBear API returned invalid JSON: $body");
        }
        return $data;
    }

    /**
     * Drops the heavy raw SERP dump (lastResult) unless requested and trims history to the last N days.
     */
    private static function shapeKeyword(array $kw, bool $includeSerp, int $historyDays): array
    {
        if (!$includeSerp) {
            unset($kw['lastResult']);
        }
        if (isset($kw['history']) && is_array($kw['history'])) {
            ksort($kw['history']);
            if ($historyDays > 0) {
                $kw['history'] = array_slice($kw['history'], -$historyDays, null, true);
            }
        }
        return $kw;
    }

    public function registerTools(McpServer $server): void
    {
        $shapeProps = [
            'history_days'       => ['type' => 'integer', 'description' => 'Keep only the last N days of position history (0 = full history)', 'default' => 30],
            'include_serp_result' => ['type' => 'boolean', 'description' => 'Include the raw top-100 SERP snapshot (lastResult). Large — off by default.', 'default' => false],
        ];

        $server->registerTool(
            'serpbear_get_domains',
            'List domains tracked in SerpBear with keyword counts, last update time and notification settings. Use the returned domain value for serpbear_get_keywords.',
            ['type' => 'object', 'properties' => new stdClass(), 'required' => []],
            [$this, 'getDomains']
        );

        $server->registerTool(
            'serpbear_get_keywords',
            'Get all tracked keywords for a SerpBear domain: current Google position, ranking URL, device, country, tags, and daily position history.',
            [
                'type'       => 'object',
                'properties' => [
                    'domain' => ['type' => 'string', 'description' => 'Domain as tracked in SerpBear, e.g. "example.com"'],
                    'tag'    => ['type' => 'string', 'description' => 'Optional: return only keywords having this tag'],
                    'device' => ['type' => 'string', 'enum' => ['desktop', 'mobile'], 'description' => 'Optional: filter by device'],
                ] + $shapeProps,
                'required' => ['domain'],
            ],
            [$this, 'getKeywords']
        );

        $server->registerTool(
            'serpbear_get_keyword',
            'Get one SerpBear keyword by ID with its full position history and ranking URL.',
            [
                'type'       => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'Keyword ID (from serpbear_get_keywords)'],
                ] + $shapeProps,
                'required' => ['id'],
            ],
            [$this, 'getKeyword']
        );

        $server->registerTool(
            'serpbear_refresh_keywords',
            'Trigger an immediate SERP re-scrape for specific SerpBear keyword IDs. Consumes scraper credits on the SerpBear side; the call can take a while.',
            [
                'type'       => 'object',
                'properties' => [
                    'ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Keyword IDs to refresh'],
                ],
                'required' => ['ids'],
            ],
            [$this, 'refreshKeywords']
        );
    }

    public function getDomains(array $args): array
    {
        return $this->request('GET', '/api/domains');
    }

    public function getKeywords(array $args): array
    {
        $domain = trim((string) ($args['domain'] ?? ''));
        if ($domain === '') {
            throw new \InvalidArgumentException('domain is required.');
        }

        $data     = $this->request('GET', '/api/keywords', ['domain' => $domain]);
        $keywords = $data['keywords'] ?? [];

        if (!empty($args['tag'])) {
            $tag      = (string) $args['tag'];
            $keywords = array_filter($keywords, fn($k) => in_array($tag, (array) ($k['tags'] ?? []), true));
        }
        if (!empty($args['device'])) {
            $device   = (string) $args['device'];
            $keywords = array_filter($keywords, fn($k) => ($k['device'] ?? '') === $device);
        }

        $includeSerp = (bool) ($args['include_serp_result'] ?? false);
        $historyDays = (int) ($args['history_days'] ?? 30);
        $keywords    = array_values(array_map(
            fn($k) => self::shapeKeyword($k, $includeSerp, $historyDays),
            $keywords
        ));

        return ['domain' => $domain, 'keywords' => $keywords, 'total' => count($keywords)];
    }

    public function getKeyword(array $args): array
    {
        $data = $this->request('GET', '/api/keyword', ['id' => (int) $args['id']]);
        if (isset($data['keyword']) && is_array($data['keyword'])) {
            $data['keyword'] = self::shapeKeyword(
                $data['keyword'],
                (bool) ($args['include_serp_result'] ?? false),
                (int) ($args['history_days'] ?? 30)
            );
        }
        return $data;
    }

    public function refreshKeywords(array $args): array
    {
        $ids = array_filter(array_map('intval', (array) ($args['ids'] ?? [])));
        if (!$ids) {
            throw new \InvalidArgumentException('ids must contain at least one keyword ID.');
        }
        $data = $this->request('POST', '/api/refresh', ['id' => implode(',', $ids)]);

        // Strip heavy SERP dumps from the echoed keywords
        if (isset($data['keywords']) && is_array($data['keywords'])) {
            $data['keywords'] = array_map(fn($k) => self::shapeKeyword($k, false, 7), $data['keywords']);
        }
        return $data;
    }
}
