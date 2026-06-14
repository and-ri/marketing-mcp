<?php

class RybbitProvider
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config ?: $_ENV;
    }

    private function baseUrl(): string
    {
        $url = $this->config['RYBBIT_BASE_URL'] ?? '';
        if ($url === '') {
            throw new \RuntimeException(
                'RYBBIT_BASE_URL is not configured (e.g. https://analytics.example.com for your self-hosted Rybbit instance).'
            );
        }
        return rtrim($url, '/');
    }

    private function apiKey(): string
    {
        $key = $this->config['RYBBIT_API_KEY'] ?? '';
        if ($key === '') {
            throw new \RuntimeException(
                'RYBBIT_API_KEY is not configured. Generate it in Rybbit → Settings → Account → API Keys.'
            );
        }
        return $key;
    }

    private function get(string $path, array $query = []): array
    {
        $url = $this->baseUrl() . $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->apiKey(),
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException("Rybbit API request failed: $err");
        }
        if ($status === 429) {
            throw new \RuntimeException('Rybbit API rate limit exceeded.');
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("Rybbit API returned HTTP $status: $body");
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new \RuntimeException("Rybbit API returned invalid JSON: $body");
        }
        return $data;
    }

    private function site(array $args): string
    {
        $site = (string) ($args['site_id'] ?? '');
        if ($site === '') {
            throw new \InvalidArgumentException('site_id is required.');
        }
        return rawurlencode($site);
    }

    private function commonParams(array $args): array
    {
        $params = [];
        foreach (['start_date', 'end_date', 'time_zone', 'filters'] as $k) {
            if (!empty($args[$k])) {
                $params[$k] = (string) $args[$k];
            }
        }
        return $params;
    }

    /** Shared input schema fragment for time range + filters. */
    private static function commonSchema(): array
    {
        return [
            'site_id'    => ['type' => 'string', 'description' => 'Rybbit site ID (numeric or string identifier)'],
            'start_date' => ['type' => 'string', 'description' => 'Start date, ISO format e.g. "2026-06-01"'],
            'end_date'   => ['type' => 'string', 'description' => 'End date, ISO format e.g. "2026-06-14"'],
            'time_zone'  => ['type' => 'string', 'description' => 'IANA timezone, e.g. "Europe/Kyiv". Default UTC.'],
            'filters'    => ['type' => 'string', 'description' => 'Optional JSON-encoded array of filters (parameter/type/value)'],
        ];
    }

    public function registerTools(McpServer $server): void
    {
        $server->registerTool(
            'rybbit_get_overview',
            'Get top-line Rybbit analytics for a site: sessions, pageviews, users, pages per session, bounce rate, and session duration.',
            [
                'type'       => 'object',
                'properties' => self::commonSchema(),
                'required'   => ['site_id'],
            ],
            [$this, 'getOverview']
        );

        $server->registerTool(
            'rybbit_get_overview_timeseries',
            'Get Rybbit overview metrics bucketed over time (sessions, pageviews, users, etc.) for trend analysis.',
            [
                'type'       => 'object',
                'properties' => self::commonSchema() + [
                    'bucket' => ['type' => 'string', 'description' => 'Time bucket: minute, five_minutes, hour, day, week, or month', 'default' => 'day'],
                ],
                'required' => ['site_id'],
            ],
            [$this, 'getOverviewTimeseries']
        );

        $server->registerTool(
            'rybbit_get_sessions',
            'Get a paginated list of Rybbit sessions with geo, device, browser, traffic source, pages, and duration.',
            [
                'type'       => 'object',
                'properties' => self::commonSchema() + [
                    'page'    => ['type' => 'integer', 'description' => 'Page number', 'default' => 1],
                    'limit'   => ['type' => 'integer', 'description' => 'Sessions per page', 'default' => 100],
                    'user_id' => ['type' => 'string', 'description' => 'Optional: filter by a specific user ID'],
                ],
                'required' => ['site_id'],
            ],
            [$this, 'getSessions']
        );

        $server->registerTool(
            'rybbit_get_event_names',
            'Get custom event names with their occurrence counts for a Rybbit site — useful for tracking conversions and key actions.',
            [
                'type'       => 'object',
                'properties' => self::commonSchema(),
                'required'   => ['site_id'],
            ],
            [$this, 'getEventNames']
        );

        $server->registerTool(
            'rybbit_get_performance',
            'Get Core Web Vitals performance for a Rybbit site: LCP, CLS, INP, FCP, TTFB at p50/p75/p90/p99 percentiles. Critical for landing page quality.',
            [
                'type'       => 'object',
                'properties' => self::commonSchema(),
                'required'   => ['site_id'],
            ],
            [$this, 'getPerformance']
        );

        $server->registerTool(
            'rybbit_get_errors',
            'Get JavaScript errors captured by Rybbit, grouped by error name with counts, session counts, and percentages.',
            [
                'type'       => 'object',
                'properties' => self::commonSchema() + [
                    'page'  => ['type' => 'integer', 'default' => 1],
                    'limit' => ['type' => 'integer', 'default' => 100],
                ],
                'required' => ['site_id'],
            ],
            [$this, 'getErrors']
        );

        $server->registerTool(
            'rybbit_get_goals',
            'Get conversion goals for a Rybbit site with total conversions, total sessions, and conversion rate per goal.',
            [
                'type'       => 'object',
                'properties' => self::commonSchema() + [
                    'page'      => ['type' => 'integer', 'default' => 1],
                    'page_size' => ['type' => 'integer', 'default' => 100],
                ],
                'required' => ['site_id'],
            ],
            [$this, 'getGoals']
        );
    }

    public function getOverview(array $args): array
    {
        $site = $this->site($args);
        return $this->get("/api/sites/$site/overview", $this->commonParams($args));
    }

    public function getOverviewTimeseries(array $args): array
    {
        $site   = $this->site($args);
        $params = $this->commonParams($args);
        $params['bucket'] = $args['bucket'] ?? 'day';
        return $this->get("/api/sites/$site/overview-bucketed", $params);
    }

    public function getSessions(array $args): array
    {
        $site   = $this->site($args);
        $params = $this->commonParams($args);
        $params['page']  = $args['page'] ?? 1;
        $params['limit'] = $args['limit'] ?? 100;
        if (!empty($args['user_id'])) {
            $params['user_id'] = (string) $args['user_id'];
        }
        return $this->get("/api/sites/$site/sessions", $params);
    }

    public function getEventNames(array $args): array
    {
        $site = $this->site($args);
        return $this->get("/api/sites/$site/events/names", $this->commonParams($args));
    }

    public function getPerformance(array $args): array
    {
        $site = $this->site($args);
        return $this->get("/api/sites/$site/performance/overview", $this->commonParams($args));
    }

    public function getErrors(array $args): array
    {
        $site   = $this->site($args);
        $params = $this->commonParams($args);
        $params['page']  = $args['page'] ?? 1;
        $params['limit'] = $args['limit'] ?? 100;
        return $this->get("/api/sites/$site/error-names", $params);
    }

    public function getGoals(array $args): array
    {
        $site   = $this->site($args);
        $params = $this->commonParams($args);
        $params['page']      = $args['page'] ?? 1;
        $params['page_size'] = $args['page_size'] ?? 100;
        return $this->get("/api/sites/$site/goals", $params);
    }
}
