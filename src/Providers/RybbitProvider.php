<?php

/**
 * Rybbit analytics (self-hosted or cloud). Read-only tools.
 * Auth: Authorization: Bearer <api key>. Organization keys cover every site in the org;
 * scoped keys need the matching resource:read scopes (analytics, sessions, events, users,
 * goals, funnels, replay, sql, org).
 * https://rybbit.com/docs/api/getting-started
 */
class RybbitProvider
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config ?: $_ENV;
    }

    // ─── HTTP ─────────────────────────────────────────────────────────────────

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

    protected function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $url = $this->baseUrl() . $path;
        $query = array_filter($query, fn($v) => $v !== null && $v !== '');
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $headers = [
            'Authorization: Bearer ' . $this->apiKey(),
            'Accept: application/json',
        ];
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => 90,
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        $resp   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            throw new \RuntimeException("Rybbit API request failed: $err");
        }
        if ($status === 401) {
            throw new \RuntimeException('Rybbit API: unauthorized — the API key is invalid or revoked. Create a new one and call auth_set_rybbit.');
        }
        if ($status === 403) {
            throw new \RuntimeException(
                "Rybbit API returned 403: $resp — the key has no access to this site/organization or lacks the required scope. "
                . 'Check site_id with rybbit_list_sites; otherwise create an Organization API key with read scopes '
                . '(analytics, sessions, events, users, goals, funnels, replay, sql, org) and save it via auth_set_rybbit.'
            );
        }
        if ($status === 429) {
            throw new \RuntimeException('Rybbit API rate limit exceeded.');
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("Rybbit API returned HTTP $status: $resp");
        }

        $data = json_decode($resp, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Rybbit API returned invalid JSON: ' . substr($resp, 0, 500));
        }
        return $data;
    }

    private function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, $query);
    }

    // ─── Params ───────────────────────────────────────────────────────────────

    private static function site(array $args): string
    {
        $site = (string) ($args['site_id'] ?? '');
        if ($site === '') {
            throw new \InvalidArgumentException('site_id is required. Call rybbit_list_sites to find it.');
        }
        return rawurlencode($site);
    }

    private static function bool(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        return filter_var($v, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
    }

    /** Common time-range + filter params accepted by almost every analytics endpoint. */
    private static function commonParams(array $args): array
    {
        $params = [];
        foreach (['start_date', 'end_date', 'time_zone', 'start_datetime', 'end_datetime',
                  'past_minutes_start', 'past_minutes_end', 'segment_id'] as $k) {
            if (isset($args[$k]) && $args[$k] !== '') {
                $params[$k] = (string) $args[$k];
            }
        }
        if (!empty($args['filters'])) {
            $params['filters'] = is_array($args['filters']) ? json_encode($args['filters']) : (string) $args['filters'];
        }
        return $params;
    }

    /** Copies the listed optional args into a query array. */
    private static function pick(array $args, array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            if (isset($args[$k]) && $args[$k] !== '') {
                $out[$k] = is_bool($args[$k]) ? ($args[$k] ? 'true' : 'false') : $args[$k];
            }
        }
        return $out;
    }

    private static function commonSchema(): array
    {
        return [
            'site_id'            => ['type' => 'string', 'description' => 'Rybbit site ID (from rybbit_list_sites)'],
            'start_date'         => ['type' => 'string', 'description' => 'Start day YYYY-MM-DD (inclusive). Send with end_date.'],
            'end_date'           => ['type' => 'string', 'description' => 'End day YYYY-MM-DD (inclusive). Send with start_date.'],
            'time_zone'          => ['type' => 'string', 'description' => 'IANA timezone, e.g. "Europe/Kyiv". Default UTC.'],
            'past_minutes_start' => ['type' => 'integer', 'description' => 'Alternative range: older boundary in minutes ago (send with past_minutes_end, e.g. 60 and 0 = last hour)'],
            'past_minutes_end'   => ['type' => 'integer', 'description' => 'Alternative range: newer boundary in minutes ago'],
            'filters'            => [
                'type'        => 'string',
                'description' => 'Optional JSON array of filters, AND-combined: [{"parameter":"pathname","type":"contains","value":["/product"]}]. '
                    . 'parameter: browser, operating_system, device_type, language, country, region, city, pathname, page_title, hostname, querystring, '
                    . 'referrer, entry_page, exit_page, channel, utm_source, utm_medium, utm_campaign, utm_term, utm_content, user_id, event_name, tag. '
                    . 'type: equals, not_equals, contains, not_contains, starts_with, ends_with, regex, not_regex, is_null, is_not_null.',
            ],
            'segment_id'         => ['type' => 'string', 'description' => 'Optional saved segment ID (ANDed with filters)'],
        ];
    }

    // ─── Tool registration ────────────────────────────────────────────────────

    public function registerTools(McpServer $server): void
    {
        $common = self::commonSchema();
        $obj = fn(array $props, array $required = ['site_id']) => [
            'type' => 'object', 'properties' => $props, 'required' => $required,
        ];

        $server->registerTool(
            'rybbit_list_sites',
            'List Rybbit organizations and their sites (site ID, domain, name, public flag). Call this first to find site_id and organization_id.',
            ['type' => 'object', 'properties' => new stdClass(), 'required' => []],
            [$this, 'listSites']
        );

        $server->registerTool(
            'rybbit_get_overview',
            'Top-line Rybbit analytics for a site: sessions, pageviews, users, pages per session, bounce rate, session duration.',
            $obj($common),
            [$this, 'getOverview']
        );

        $server->registerTool(
            'rybbit_get_overview_timeseries',
            'Rybbit overview metrics (sessions, pageviews, users, bounce rate, duration) bucketed over time for trend analysis.',
            $obj($common + [
                'bucket' => ['type' => 'string', 'enum' => ['minute', 'five_minutes', 'ten_minutes', 'fifteen_minutes', 'hour', 'day', 'week', 'month', 'year'], 'default' => 'day'],
            ]),
            [$this, 'getOverviewTimeseries']
        );

        $server->registerTool(
            'rybbit_get_metric',
            'Break down Rybbit sessions/pageviews/bounce rate by one dimension: pathname, entry_page, exit_page, page_title, referrer, channel, '
            . 'utm_source, utm_medium, utm_campaign, country, region, city, browser, operating_system, device_type, language, event_name, hostname. '
            . 'Sorted by count desc with percentages.',
            $obj($common + [
                'parameter' => ['type' => 'string', 'description' => 'Dimension to break down by, e.g. "channel", "referrer", "country", "entry_page"'],
                'limit'     => ['type' => 'integer', 'default' => 100],
                'page'      => ['type' => 'integer', 'default' => 1],
            ], ['site_id', 'parameter']),
            [$this, 'getMetric']
        );

        $server->registerTool(
            'rybbit_get_pages',
            'Rybbit page report: pageviews, sessions, entries, exits, time on page and bounce rate per page or per section (first path segment). Sortable and searchable.',
            $obj($common + [
                'group'  => ['type' => 'string', 'enum' => ['page', 'section'], 'default' => 'page'],
                'mode'   => ['type' => 'string', 'enum' => ['all', 'entry', 'exit'], 'default' => 'all'],
                'sort'   => ['type' => 'string', 'enum' => ['pageviews', 'sessions', 'entries', 'exits', 'time_on_page', 'bounce_rate'], 'default' => 'pageviews'],
                'order'  => ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => 'desc'],
                'search' => ['type' => 'string', 'description' => 'Case-insensitive match on path or title (page rows only)'],
                'limit'  => ['type' => 'integer', 'description' => '1–100', 'default' => 25],
                'page'   => ['type' => 'integer', 'default' => 1],
            ]),
            [$this, 'getPages']
        );

        $server->registerTool(
            'rybbit_get_live_visitors',
            'Number of unique visitors active on a Rybbit site in the last N minutes.',
            $obj([
                'site_id' => $common['site_id'],
                'minutes' => ['type' => 'integer', 'default' => 5],
            ]),
            [$this, 'getLiveVisitors']
        );

        $server->registerTool(
            'rybbit_get_sessions',
            'List Rybbit sessions with geo, device, browser, traffic source, entry/exit page, pageviews, events and duration. '
            . 'Use view="replay" for sessions that have a session replay, "errors" for sessions with JS errors, "converted" for goal conversions, "bounced" for bounces.',
            $obj($common + [
                'page'          => ['type' => 'integer', 'default' => 1],
                'limit'         => ['type' => 'integer', 'default' => 50],
                'user_id'       => ['type' => 'string', 'description' => 'Identified user ID or anonymous device ID'],
                'view'          => ['type' => 'string', 'enum' => ['all', 'identified', 'replay', 'converted', 'bounced', 'errors'], 'default' => 'all'],
                'sort_by'       => ['type' => 'string', 'enum' => ['started', 'ended', 'duration', 'pageviews', 'events', 'errors'], 'default' => 'ended'],
                'sort_order'    => ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => 'desc'],
                'include_goals' => ['type' => 'boolean', 'default' => false],
                'min_duration'  => ['type' => 'integer', 'description' => 'Min session duration, seconds'],
                'min_pageviews' => ['type' => 'integer'],
            ]),
            [$this, 'getSessions']
        );

        $server->registerTool(
            'rybbit_get_session',
            'Full details of one Rybbit session: source, device, location, entry/exit pages and the ordered event timeline (pageviews, custom events, clicks, errors, goals).',
            $obj([
                'site_id'    => $common['site_id'],
                'session_id' => ['type' => 'string'],
                'limit'      => ['type' => 'integer', 'description' => 'Events to return', 'default' => 100],
                'offset'     => ['type' => 'integer', 'default' => 0],
            ], ['site_id', 'session_id']),
            [$this, 'getSession']
        );

        $server->registerTool(
            'rybbit_list_session_replays',
            'List recorded session replays for a Rybbit site (newest first): session ID, user, duration, entry URL, event count, device, location.',
            $obj($common + [
                'limit'        => ['type' => 'integer', 'default' => 20],
                'offset'       => ['type' => 'integer', 'default' => 0],
                'user_id'      => ['type' => 'string'],
                'min_duration' => ['type' => 'integer', 'description' => 'Minimum replay duration, seconds'],
            ]),
            [$this, 'listSessionReplays']
        );

        $server->registerTool(
            'rybbit_get_session_replay',
            'Analyze one Rybbit session replay. Raw rrweb data is decoded into a readable timeline: page navigations, clicks with the clicked element '
            . '(tag, text, href, id/class), rage clicks, scroll depth per page, form fields interacted with (values are never returned), viewport resizes, '
            . 'and console errors. Use it to understand HOW a visitor behaved and where they struggled.',
            $obj([
                'site_id'          => $common['site_id'],
                'session_id'       => ['type' => 'string'],
                'max_timeline'     => ['type' => 'integer', 'description' => 'Max timeline entries to return', 'default' => 200],
                'include_raw'      => ['type' => 'boolean', 'description' => 'Also return the first raw rrweb events (large, for debugging)', 'default' => false],
                'raw_limit'        => ['type' => 'integer', 'default' => 50],
            ], ['site_id', 'session_id']),
            [$this, 'getSessionReplay']
        );

        $server->registerTool(
            'rybbit_get_users',
            'List Rybbit users (visitors) with traits, location, device, first/last seen, sessions, pageviews and events. Supports search and new/identified-only filters.',
            $obj($common + [
                'page'            => ['type' => 'integer', 'default' => 1],
                'page_size'       => ['type' => 'integer', 'default' => 50],
                'sort_by'         => ['type' => 'string', 'enum' => ['first_seen', 'last_seen', 'pageviews', 'sessions', 'events'], 'default' => 'last_seen'],
                'sort_order'      => ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => 'desc'],
                'identified_only' => ['type' => 'boolean', 'default' => false],
                'new_only'        => ['type' => 'boolean', 'default' => false],
                'min_sessions'    => ['type' => 'integer'],
                'search'          => ['type' => 'string'],
                'search_field'    => ['type' => 'string', 'enum' => ['user_id', 'username', 'name', 'email'], 'default' => 'username'],
            ]),
            [$this, 'getUsers']
        );

        $server->registerTool(
            'rybbit_get_user',
            'Profile of one Rybbit user: activity totals, first-touch acquisition (referrer, channel, UTM, entry page), last channel, devices, locations, and p75 Web Vitals.',
            $obj($common + [
                'user_id' => ['type' => 'string', 'description' => 'Device ID or identified user ID'],
            ], ['site_id', 'user_id']),
            [$this, 'getUser']
        );

        $server->registerTool(
            'rybbit_get_events',
            'Raw Rybbit event stream (newest first): pageviews, custom events, outbound clicks, button clicks, form submits, errors — with page, session, user and device fields. Cursor-paginated.',
            $obj($common + [
                'page_size'     => ['type' => 'integer', 'default' => 50],
                'before_cursor' => ['type' => 'string', 'description' => 'cursor.oldestCursor from the previous page to scroll back'],
            ]),
            [$this, 'getEvents']
        );

        $server->registerTool(
            'rybbit_get_event_names',
            'Custom event names with occurrence counts for a Rybbit site — useful for tracking conversions and key actions.',
            $obj($common),
            [$this, 'getEventNames']
        );

        $server->registerTool(
            'rybbit_get_event_properties',
            'Property key/value breakdown with counts for one custom event (e.g. which plan, product or button variant).',
            $obj($common + [
                'event_name' => ['type' => 'string'],
            ], ['site_id', 'event_name']),
            [$this, 'getEventProperties']
        );

        $server->registerTool(
            'rybbit_get_goals',
            'Conversion goals for a Rybbit site with total conversions, total sessions, and conversion rate per goal.',
            $obj($common + [
                'page'      => ['type' => 'integer', 'default' => 1],
                'page_size' => ['type' => 'integer', 'description' => '1–100', 'default' => 100],
            ]),
            [$this, 'getGoals']
        );

        $server->registerTool(
            'rybbit_get_funnels',
            'List saved Rybbit funnels with their steps. Pass the steps to rybbit_analyze_funnel to compute conversion.',
            $obj(['site_id' => $common['site_id']]),
            [$this, 'getFunnels']
        );

        $server->registerTool(
            'rybbit_analyze_funnel',
            'Compute a Rybbit funnel (read-only): sessions, conversion rate and drop-off per step. Steps are completed in order within a session.',
            $obj($common + [
                'steps' => [
                    'type'        => 'array',
                    'description' => 'At least 2 steps. Each: {type: page|event|outbound|button_click|form_submit|copy, value: path pattern (* within a segment, ** across) or event name, name?: display name}',
                    'items'       => [
                        'type'       => 'object',
                        'properties' => [
                            'type'  => ['type' => 'string'],
                            'value' => ['type' => 'string'],
                            'name'  => ['type' => 'string'],
                        ],
                        'required' => ['type'],
                    ],
                ],
            ], ['site_id', 'steps']),
            [$this, 'analyzeFunnel']
        );

        $server->registerTool(
            'rybbit_get_journeys',
            'Most common page paths visitors take through a Rybbit site (sessions with 2+ pages), optionally anchored to a landing page or a destination.',
            $obj($common + [
                'steps'       => ['type' => 'integer', 'description' => 'Max steps per journey, 2–10', 'default' => 3],
                'limit'       => ['type' => 'integer', 'description' => '1–500', 'default' => 50],
                'step_filters' => ['type' => 'string', 'description' => 'JSON object mapping 0-based step index to path pattern, e.g. {"0": "/"} (step 0 = landing page)'],
                'ends_at'     => ['type' => 'string', 'description' => 'Page/pattern journeys must reach, e.g. "/checkout/**"'],
                'group_by'    => ['type' => 'string', 'enum' => ['path', 'section'], 'default' => 'path'],
                'goal_id'     => ['type' => 'integer', 'description' => 'Adds conversions per journey for this goal'],
            ]),
            [$this, 'getJourneys']
        );

        $server->registerTool(
            'rybbit_get_retention',
            'Rybbit cohort retention: share of visitors returning in each following day/week.',
            $obj($common + [
                'mode'  => ['type' => 'string', 'enum' => ['day', 'week'], 'default' => 'week'],
                'range' => ['type' => 'integer', 'description' => 'Trailing days (7–365) when no date range is given', 'default' => 90],
            ]),
            [$this, 'getRetention']
        );

        $server->registerTool(
            'rybbit_get_performance',
            'Core Web Vitals for a Rybbit site: LCP, CLS, INP, FCP, TTFB at p50/p75/p90/p99.',
            $obj($common),
            [$this, 'getPerformance']
        );

        $server->registerTool(
            'rybbit_get_performance_by_dimension',
            'Core Web Vitals broken down by pathname, country, device_type, browser, operating_system or region — find slow pages and devices.',
            $obj($common + [
                'dimension'  => ['type' => 'string', 'enum' => ['pathname', 'country', 'device_type', 'browser', 'operating_system', 'region']],
                'limit'      => ['type' => 'integer', 'default' => 50],
                'page'       => ['type' => 'integer', 'default' => 1],
                'sort_by'    => ['type' => 'string', 'description' => 'event_count or <metric>_avg/_p50/_p75/_p90/_p99, e.g. "lcp_p75"', 'default' => 'event_count'],
                'sort_order' => ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => 'desc'],
            ], ['site_id', 'dimension']),
            [$this, 'getPerformanceByDimension']
        );

        $server->registerTool(
            'rybbit_get_errors',
            'JavaScript errors captured by Rybbit, grouped by message with occurrence and session counts. Use rybbit_get_error_events for stack traces.',
            $obj($common + [
                'page'  => ['type' => 'integer', 'default' => 1],
                'limit' => ['type' => 'integer', 'default' => 50],
            ]),
            [$this, 'getErrors']
        );

        $server->registerTool(
            'rybbit_get_error_events',
            'Individual occurrences of one JS error: page, browser, OS, device, session, stack trace, file and line.',
            $obj($common + [
                'error_message' => ['type' => 'string', 'description' => 'Exact error message (the "value" from rybbit_get_errors)'],
                'page'          => ['type' => 'integer', 'default' => 1],
                'limit'         => ['type' => 'integer', 'default' => 20],
            ], ['site_id', 'error_message']),
            [$this, 'getErrorEvents']
        );

        $bucket = ['type' => 'string', 'enum' => ['minute', 'five_minutes', 'ten_minutes', 'fifteen_minutes', 'hour', 'day', 'week', 'month', 'year'], 'default' => 'day'];
        $funnelSteps = [
            'type'        => 'array',
            'description' => 'Funnel steps (same format as rybbit_analyze_funnel)',
            'items'       => ['type' => 'object', 'properties' => ['type' => ['type' => 'string'], 'value' => ['type' => 'string'], 'name' => ['type' => 'string']], 'required' => ['type']],
        ];

        $server->registerTool(
            'rybbit_get_event_timeseries',
            'Time series of the top custom events (by total count) on a Rybbit site.',
            $obj($common + [
                'bucket' => $bucket,
                'limit'  => ['type' => 'integer', 'description' => 'Top N events, 1–10', 'default' => 5],
            ]),
            [$this, 'getEventTimeseries']
        );

        $server->registerTool(
            'rybbit_get_event_counts',
            'Event volume per time bucket split by type: pageviews, custom events, outbound clicks, errors, button clicks, copies, form submits, input changes, performance.',
            $obj($common + ['bucket' => $bucket]),
            [$this, 'getEventCounts']
        );

        $server->registerTool(
            'rybbit_get_outbound_links',
            'Outbound links clicked on a Rybbit site (where visitors leave to) with click counts.',
            $obj($common),
            [$this, 'getOutboundLinks']
        );

        $server->registerTool(
            'rybbit_get_goal_timeseries',
            'Conversions over time for one or more Rybbit goals.',
            $obj($common + [
                'goal_ids' => ['type' => 'string', 'description' => 'Comma-separated goal IDs, e.g. "1,2" (from rybbit_get_goals)'],
                'bucket'   => $bucket,
            ], ['site_id', 'goal_ids']),
            [$this, 'getGoalTimeseries']
        );

        $server->registerTool(
            'rybbit_get_goal_sessions',
            'Sessions that converted on a given Rybbit goal — drill down into who converted and how.',
            $obj($common + [
                'goal_id' => ['type' => 'integer'],
                'page'    => ['type' => 'integer', 'default' => 1],
                'limit'   => ['type' => 'integer', 'default' => 25],
            ], ['site_id', 'goal_id']),
            [$this, 'getGoalSessions']
        );

        $server->registerTool(
            'rybbit_get_funnel_step_sessions',
            'Sessions that reached or dropped off at a given funnel step. Combine with replays_only=true and rybbit_get_session_replay to watch why people drop.',
            $obj($common + [
                'steps'        => $funnelSteps,
                'step_number'  => ['type' => 'integer', 'description' => '1-indexed step'],
                'mode'         => ['type' => 'string', 'enum' => ['reached', 'dropped']],
                'replays_only' => ['type' => 'boolean', 'default' => false],
                'page'         => ['type' => 'integer', 'default' => 1],
                'limit'        => ['type' => 'integer', 'default' => 25],
            ], ['site_id', 'steps', 'step_number', 'mode']),
            [$this, 'getFunnelStepSessions']
        );

        $server->registerTool(
            'rybbit_get_page_trends',
            'Pageview/session trend over time for specific pages or sections (e.g. compare /pricing before and after a change).',
            $obj($common + [
                'paths'    => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Exact paths, e.g. ["/pricing"]'],
                'sections' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'First path segments, e.g. ["/blog"]'],
                'bucket'   => $bucket,
            ]),
            [$this, 'getPageTrends']
        );

        $server->registerTool(
            'rybbit_get_session_locations',
            'Session counts by geographic location (city, country, lat/lon).',
            $obj($common),
            [$this, 'getSessionLocations']
        );

        $server->registerTool(
            'rybbit_get_bots_overview',
            'Bot traffic on a Rybbit site: total bot requests and share, AI requests split into AI agents (user-triggered) and AI crawlers (training/answer engines), and counts per detection layer.',
            $obj($common + [
                'layer' => ['type' => 'string', 'enum' => ['ua_pattern', 'header_heuristics', 'client_signals', 'bot_asn', 'rate_anomaly']],
            ]),
            [$this, 'getBotsOverview']
        );

        $server->registerTool(
            'rybbit_get_bots_by_dimension',
            'Bot traffic broken down by bot_name, bot_operator, bot_category, bot_purpose, pathname, country, asn_org, etc. '
            . 'Use purpose="ai_crawler" / "ai_search" / "ai_agent" to see which AI systems (GPTBot, ClaudeBot, PerplexityBot…) read which pages.',
            $obj($common + [
                'dimension' => ['type' => 'string', 'enum' => ['bot_name', 'bot_operator', 'bot_category', 'bot_purpose', 'matched_ua_pattern', 'pathname', 'hostname', 'referrer', 'country', 'region', 'city', 'device_type', 'browser', 'operating_system', 'asn_org', 'asn_provider']],
                'purpose'   => ['type' => 'string', 'enum' => ['ai', 'ai_crawler', 'ai_training', 'ai_search', 'ai_agent', 'search', 'social_preview', 'seo', 'monitoring', 'security', 'scripted', 'headless']],
                'layer'     => ['type' => 'string', 'enum' => ['ua_pattern', 'header_heuristics', 'client_signals', 'bot_asn', 'rate_anomaly']],
                'limit'     => ['type' => 'integer', 'default' => 50],
                'page'      => ['type' => 'integer', 'default' => 1],
            ], ['site_id', 'dimension']),
            [$this, 'getBotsByDimension']
        );

        $server->registerTool(
            'rybbit_get_user_trait_keys',
            'Custom user trait keys (e.g. plan, company) set via identify(), with the number of users having each.',
            $obj(['site_id' => $common['site_id']]),
            [$this, 'getUserTraitKeys']
        );

        $server->registerTool(
            'rybbit_get_segments',
            'Saved Rybbit segments (named filter sets). Pass segment_id to other tools to apply one.',
            $obj(['site_id' => $common['site_id']]),
            [$this, 'getSegments']
        );

        $server->registerTool(
            'rybbit_get_annotations',
            'Annotations on the Rybbit timeline (releases, campaign launches, incidents) — use to explain traffic changes.',
            $obj([
                'site_id'    => $common['site_id'],
                'start_date' => $common['start_date'],
                'end_date'   => $common['end_date'],
                'time_zone'  => $common['time_zone'],
            ]),
            [$this, 'getAnnotations']
        );

        $server->registerTool(
            'rybbit_create_annotation',
            'WRITE: add an annotation to the Rybbit timeline (e.g. "Google Ads campaign X launched"). Only call when the user explicitly asks to add one.',
            $obj([
                'site_id'     => $common['site_id'],
                'title'       => ['type' => 'string', 'description' => 'Max 120 chars'],
                'date'        => ['type' => 'string', 'description' => 'YYYY-MM-DD or ISO 8601 timestamp with offset'],
                'end_date'    => ['type' => 'string', 'description' => 'Optional end of a range'],
                'description' => ['type' => 'string', 'description' => 'Max 2000 chars'],
                'color'       => ['type' => 'string', 'enum' => ['amber', 'rose', 'sky', 'violet', 'lime']],
                'icon'        => ['type' => 'string', 'description' => 'Single emoji'],
            ], ['site_id', 'title', 'date']),
            [$this, 'createAnnotation']
        );

        $server->registerTool(
            'rybbit_run_sql',
            'Run a read-only ClickHouse SQL query over Rybbit raw events (table "scoped_events"; SELECT / WITH … SELECT only; max 1000 rows, 60 s). '
            . 'Use for questions the other tools cannot answer. Requires organization_id (from rybbit_list_sites) and the sql:read scope. '
            . 'Use single quotes for strings; no double-quoted/backtick identifiers.',
            [
                'type'       => 'object',
                'properties' => [
                    'organization_id' => ['type' => 'string'],
                    'query'           => ['type' => 'string', 'description' => 'SELECT … FROM scoped_events …'],
                    'site_id'         => ['type' => 'integer', 'description' => 'Optional: restrict to one site'],
                ],
                'required' => ['organization_id', 'query'],
            ],
            [$this, 'runSql']
        );
    }

    // ─── Handlers ─────────────────────────────────────────────────────────────

    public function listSites(array $args): array
    {
        $orgs = $this->get('/api/organizations');
        $out  = [];
        foreach ($orgs as $org) {
            $out[] = [
                'organization_id' => $org['id'] ?? null,
                'name'            => $org['name'] ?? null,
                'role'            => $org['role'] ?? null,
                'sites'           => array_map(fn($s) => [
                    'site_id' => $s['siteId'] ?? $s['id'] ?? null,
                    'domain'  => $s['domain'] ?? null,
                    'name'    => $s['name'] ?? null,
                    'public'  => $s['public'] ?? null,
                ], $org['sites'] ?? []),
            ];
        }
        return ['organizations' => $out];
    }

    public function getOverview(array $args): array
    {
        return $this->get('/api/sites/' . self::site($args) . '/overview', self::commonParams($args));
    }

    public function getOverviewTimeseries(array $args): array
    {
        $q = self::commonParams($args) + ['bucket' => $args['bucket'] ?? 'day'];
        return $this->get('/api/sites/' . self::site($args) . '/overview/time-series', $q);
    }

    public function getMetric(array $args): array
    {
        $q = self::commonParams($args) + ['parameter' => (string) $args['parameter']] + self::pick($args, ['limit', 'page']);
        return $this->get('/api/sites/' . self::site($args) . '/metric', $q);
    }

    public function getPages(array $args): array
    {
        $q = self::commonParams($args) + self::pick($args, ['group', 'mode', 'sort', 'order', 'search', 'limit', 'page']);
        return $this->get('/api/sites/' . self::site($args) . '/pages', $q);
    }

    public function getLiveVisitors(array $args): array
    {
        return $this->get('/api/sites/' . self::site($args) . '/live-user-count', ['minutes' => (int) ($args['minutes'] ?? 5)]);
    }

    public function getSessions(array $args): array
    {
        $q = self::commonParams($args) + [
            'page'  => $args['page'] ?? 1,
            'limit' => $args['limit'] ?? 50,
        ] + self::pick($args, ['user_id', 'view', 'sort_by', 'sort_order', 'min_duration', 'min_pageviews']);
        if (isset($args['include_goals'])) {
            $q['include_goals'] = self::bool($args['include_goals']);
        }
        return $this->get('/api/sites/' . self::site($args) . '/sessions', $q);
    }

    public function getSession(array $args): array
    {
        $sid = rawurlencode((string) $args['session_id']);
        return $this->get('/api/sites/' . self::site($args) . "/sessions/$sid", self::pick($args, ['limit', 'offset']));
    }

    public function listSessionReplays(array $args): array
    {
        $q = self::commonParams($args) + [
            'limit'  => $args['limit'] ?? 20,
            'offset' => $args['offset'] ?? 0,
        ];
        if (!empty($args['user_id'])) {
            $q['userId'] = (string) $args['user_id'];
        }
        if (!empty($args['min_duration'])) {
            $q['minDuration'] = (int) $args['min_duration'];
        }
        $data = $this->get('/api/sites/' . self::site($args) . '/session-replay/list', $q);
        foreach ($data['data'] ?? [] as $i => $row) {
            if (!empty($row['page_url'])) {
                $data['data'][$i]['page_url'] = RrwebAnalyzer::cleanUrl((string) $row['page_url']);
            }
        }
        return $data;
    }

    public function getSessionReplay(array $args): array
    {
        $sid  = rawurlencode((string) $args['session_id']);
        $data = $this->get('/api/sites/' . self::site($args) . "/session-replay/$sid");

        $events = $data['events'] ?? $data['data']['events'] ?? [];
        $meta   = $data['metadata'] ?? $data['data']['metadata'] ?? null;
        if (is_array($meta) && !empty($meta['page_url'])) {
            $meta['page_url'] = RrwebAnalyzer::cleanUrl((string) $meta['page_url']);
        }

        $result = [
            'metadata' => $meta,
            'analysis' => RrwebAnalyzer::analyze($events, max(1, (int) ($args['max_timeline'] ?? 200))),
        ];
        if (!empty($args['include_raw'])) {
            $result['raw_events'] = array_slice($events, 0, max(1, (int) ($args['raw_limit'] ?? 50)));
        }
        return $result;
    }

    public function getUsers(array $args): array
    {
        $q = self::commonParams($args) + [
            'page'      => $args['page'] ?? 1,
            'page_size' => $args['page_size'] ?? 50,
        ] + self::pick($args, ['sort_by', 'sort_order', 'min_sessions', 'search', 'search_field']);
        foreach (['identified_only', 'new_only'] as $b) {
            if (isset($args[$b])) {
                $q[$b] = self::bool($args[$b]);
            }
        }
        return $this->get('/api/sites/' . self::site($args) . '/users', $q);
    }

    public function getUser(array $args): array
    {
        $uid = rawurlencode((string) $args['user_id']);
        return $this->get('/api/sites/' . self::site($args) . "/users/$uid", self::commonParams($args));
    }

    public function getEvents(array $args): array
    {
        $q = self::commonParams($args) + ['page_size' => $args['page_size'] ?? 50] + self::pick($args, ['before_cursor']);
        return $this->get('/api/sites/' . self::site($args) . '/events', $q);
    }

    public function getEventNames(array $args): array
    {
        return $this->get('/api/sites/' . self::site($args) . '/events/names', self::commonParams($args));
    }

    public function getEventProperties(array $args): array
    {
        $q = self::commonParams($args) + ['event_name' => (string) $args['event_name']];
        return $this->get('/api/sites/' . self::site($args) . '/events/properties', $q);
    }

    public function getGoals(array $args): array
    {
        $q = self::commonParams($args) + [
            'page'      => $args['page'] ?? 1,
            'page_size' => min(max((int) ($args['page_size'] ?? 100), 1), 100),
        ];
        return $this->get('/api/sites/' . self::site($args) . '/goals', $q);
    }

    public function getFunnels(array $args): array
    {
        return $this->get('/api/sites/' . self::site($args) . '/funnels');
    }

    public function analyzeFunnel(array $args): array
    {
        $steps = $args['steps'] ?? [];
        if (!is_array($steps) || count($steps) < 2) {
            throw new \InvalidArgumentException('steps must contain at least 2 steps.');
        }
        return $this->request('POST', '/api/sites/' . self::site($args) . '/funnels/analyze', self::commonParams($args), ['steps' => array_values($steps)]);
    }

    public function getJourneys(array $args): array
    {
        $q = self::commonParams($args) + self::pick($args, ['steps', 'limit']);
        $map = ['step_filters' => 'stepFilters', 'ends_at' => 'endsAt', 'group_by' => 'groupBy', 'goal_id' => 'goalId'];
        foreach ($map as $from => $to) {
            if (isset($args[$from]) && $args[$from] !== '') {
                $q[$to] = $args[$from];
            }
        }
        return $this->get('/api/sites/' . self::site($args) . '/journeys', $q);
    }

    public function getRetention(array $args): array
    {
        $q = self::commonParams($args) + self::pick($args, ['mode', 'range']);
        return $this->get('/api/sites/' . self::site($args) . '/retention', $q);
    }

    public function getPerformance(array $args): array
    {
        return $this->get('/api/sites/' . self::site($args) . '/performance/overview', self::commonParams($args));
    }

    public function getPerformanceByDimension(array $args): array
    {
        $q = self::commonParams($args) + ['dimension' => (string) $args['dimension']]
            + self::pick($args, ['limit', 'page', 'sort_by', 'sort_order']);
        return $this->get('/api/sites/' . self::site($args) . '/performance/by-dimension', $q);
    }

    public function getErrors(array $args): array
    {
        $q = self::commonParams($args) + [
            'page'  => $args['page'] ?? 1,
            'limit' => $args['limit'] ?? 50,
        ];
        return $this->get('/api/sites/' . self::site($args) . '/errors/names', $q);
    }

    public function getErrorEvents(array $args): array
    {
        $q = self::commonParams($args) + [
            'errorMessage' => (string) $args['error_message'],
            'page'         => $args['page'] ?? 1,
            'limit'        => $args['limit'] ?? 20,
        ];
        return $this->get('/api/sites/' . self::site($args) . '/errors/events', $q);
    }

    public function getEventTimeseries(array $args): array
    {
        $q = self::commonParams($args) + ['bucket' => $args['bucket'] ?? 'day'] + self::pick($args, ['limit']);
        return $this->get('/api/sites/' . self::site($args) . '/events/time-series', $q);
    }

    public function getEventCounts(array $args): array
    {
        $q = self::commonParams($args) + ['bucket' => $args['bucket'] ?? 'day'];
        return $this->get('/api/sites/' . self::site($args) . '/events/count', $q);
    }

    public function getOutboundLinks(array $args): array
    {
        return $this->get('/api/sites/' . self::site($args) . '/events/outbound', self::commonParams($args));
    }

    public function getGoalTimeseries(array $args): array
    {
        $q = self::commonParams($args) + [
            'goal_ids' => (string) $args['goal_ids'],
            'bucket'   => $args['bucket'] ?? 'day',
        ];
        return $this->get('/api/sites/' . self::site($args) . '/goals/time-series', $q);
    }

    public function getGoalSessions(array $args): array
    {
        $gid = rawurlencode((string) $args['goal_id']);
        $q   = self::commonParams($args) + self::pick($args, ['page', 'limit']);
        return $this->get('/api/sites/' . self::site($args) . "/goals/$gid/sessions", $q);
    }

    public function getFunnelStepSessions(array $args): array
    {
        $steps = $args['steps'] ?? [];
        if (!is_array($steps) || count($steps) < 2) {
            throw new \InvalidArgumentException('steps must contain at least 2 steps.');
        }
        $step = (int) $args['step_number'];
        $q    = self::commonParams($args) + ['mode' => (string) $args['mode']] + self::pick($args, ['page', 'limit']);
        if (isset($args['replays_only'])) {
            $q['replays_only'] = self::bool($args['replays_only']);
        }
        return $this->request('POST', '/api/sites/' . self::site($args) . "/funnels/$step/sessions", $q, ['steps' => array_values($steps)]);
    }

    public function getPageTrends(array $args): array
    {
        if (empty($args['paths']) && empty($args['sections'])) {
            throw new \InvalidArgumentException('Pass at least one of paths or sections.');
        }
        $q = self::commonParams($args) + ['bucket' => $args['bucket'] ?? 'day'];
        foreach (['paths', 'sections'] as $k) {
            if (!empty($args[$k])) {
                $q[$k] = json_encode(array_values((array) $args[$k]));
            }
        }
        return $this->get('/api/sites/' . self::site($args) . '/pages/trends', $q);
    }

    public function getSessionLocations(array $args): array
    {
        return $this->get('/api/sites/' . self::site($args) . '/sessions/locations', self::commonParams($args));
    }

    public function getBotsOverview(array $args): array
    {
        $q = self::commonParams($args) + self::pick($args, ['layer']);
        return $this->get('/api/sites/' . self::site($args) . '/bots/overview', $q);
    }

    public function getBotsByDimension(array $args): array
    {
        $q = self::commonParams($args) + ['dimension' => (string) $args['dimension']]
            + self::pick($args, ['purpose', 'layer', 'limit', 'page']);
        return $this->get('/api/sites/' . self::site($args) . '/bots/by-dimension', $q);
    }

    public function getUserTraitKeys(array $args): array
    {
        return $this->get('/api/sites/' . self::site($args) . '/user-traits/keys');
    }

    public function getSegments(array $args): array
    {
        return ['segments' => $this->get('/api/sites/' . self::site($args) . '/segments')];
    }

    public function getAnnotations(array $args): array
    {
        $q = self::pick($args, ['start_date', 'end_date', 'time_zone']);
        return $this->get('/api/sites/' . self::site($args) . '/annotations', $q);
    }

    public function createAnnotation(array $args): array
    {
        $body = [
            'title' => mb_substr((string) $args['title'], 0, 120),
            'date'  => (string) $args['date'],
        ];
        foreach (['end_date' => 'endDate', 'description' => 'description', 'color' => 'color', 'icon' => 'icon'] as $from => $to) {
            if (!empty($args[$from])) {
                $body[$to] = (string) $args[$from];
            }
        }
        return $this->request('POST', '/api/sites/' . self::site($args) . '/annotations', [], $body);
    }

    public function runSql(array $args): array
    {
        $org  = rawurlencode((string) $args['organization_id']);
        $body = ['query' => (string) $args['query']];
        if (!empty($args['site_id'])) {
            $body['siteId'] = (int) $args['site_id'];
        }
        return $this->request('POST', "/api/organizations/$org/analytics/query", [], $body);
    }
}

/**
 * Turns an rrweb event stream into a compact, LLM-readable behaviour summary.
 * Never returns typed input values.
 */
class RrwebAnalyzer
{
    // rrweb EventType
    private const FULL_SNAPSHOT = 2;
    private const INCREMENTAL   = 3;
    private const META          = 4;
    private const CUSTOM        = 5;
    private const PLUGIN        = 6;

    // IncrementalSource
    private const SRC_MUTATION  = 0;
    private const SRC_MOUSEMOVE = 1;
    private const SRC_MOUSE_INT = 2;
    private const SRC_SCROLL    = 3;
    private const SRC_RESIZE    = 4;
    private const SRC_INPUT     = 5;
    private const SRC_TOUCHMOVE = 6;
    private const SRC_LOG       = 11;

    // MouseInteractions
    private const MI_CLICK    = 2;
    private const MI_DBLCLICK = 4;
    private const MI_TOUCHEND = 9;

    /** @var array<int, array> node id → compact element descriptor */
    private array $nodes = [];
    /** @var array<int, int> node id → parent id */
    private array $parent = [];

    public static function analyze(array $events, int $maxTimeline): array
    {
        return (new self())->run($events, $maxTimeline);
    }

    private function run(array $events, int $maxTimeline): array
    {
        if (!$events) {
            return ['note' => 'Replay has no events.'];
        }
        usort($events, fn($a, $b) => ($a['timestamp'] ?? 0) <=> ($b['timestamp'] ?? 0));

        $t0       = (int) ($events[0]['timestamp'] ?? 0);
        $tEnd     = (int) (end($events)['timestamp'] ?? $t0);
        $timeline = [];
        $clicks   = [];
        $inputs   = [];
        $counts   = ['mouse_moves' => 0, 'scroll_events' => 0, 'dom_mutations' => 0, 'touch_moves' => 0];
        $pages    = [];
        $curPage  = null;
        $viewport = null;

        $at = fn(array $e) => round((((int) ($e['timestamp'] ?? $t0)) - $t0) / 1000, 1);

        foreach ($events as $e) {
            $type = $e['type'] ?? null;
            $d    = $e['data'] ?? [];

            if ($type === self::META) {
                $href = isset($d['href']) ? self::cleanUrl((string) $d['href']) : null;
                $viewport = isset($d['width']) ? ($d['width'] . 'x' . ($d['height'] ?? '?')) : $viewport;
                if ($href && $href !== $curPage) {
                    $curPage = $href;
                    $pages[$href] ??= ['url' => $href, 'first_at_s' => $at($e), 'max_scroll_y' => 0, 'page_height' => null];
                    $timeline[] = ['at_s' => $at($e), 'event' => 'navigate', 'url' => $href, 'viewport' => $viewport];
                }
                continue;
            }

            if ($type === self::FULL_SNAPSHOT) {
                $this->nodes = [];
                $this->parent = [];
                if (isset($d['node'])) {
                    $this->indexNode($d['node'], null);
                }
                if ($curPage !== null && isset($d['initialOffset']['top'])) {
                    $pages[$curPage]['max_scroll_y'] = max($pages[$curPage]['max_scroll_y'], (int) $d['initialOffset']['top']);
                }
                continue;
            }

            if ($type === self::CUSTOM) {
                $timeline[] = ['at_s' => $at($e), 'event' => 'custom', 'tag' => $d['tag'] ?? null];
                continue;
            }

            if ($type === self::PLUGIN && str_contains((string) ($d['plugin'] ?? ''), 'console')) {
                $p = $d['payload'] ?? [];
                if (in_array($p['level'] ?? '', ['error', 'warn'], true)) {
                    $timeline[] = ['at_s' => $at($e), 'event' => 'console_' . $p['level'], 'message' => self::short(implode(' ', (array) ($p['payload'] ?? [])), 200)];
                }
                continue;
            }

            if ($type !== self::INCREMENTAL) {
                continue;
            }

            switch ($d['source'] ?? null) {
                case self::SRC_MUTATION:
                    $counts['dom_mutations']++;
                    foreach ($d['adds'] ?? [] as $add) {
                        if (isset($add['node'])) {
                            $this->indexNode($add['node'], $add['parentId'] ?? null);
                        }
                    }
                    break;

                case self::SRC_MOUSEMOVE:
                    $counts['mouse_moves']++;
                    break;

                case self::SRC_TOUCHMOVE:
                    $counts['touch_moves']++;
                    break;

                case self::SRC_MOUSE_INT:
                    $mi = $d['type'] ?? null;
                    if (in_array($mi, [self::MI_CLICK, self::MI_DBLCLICK, self::MI_TOUCHEND], true)) {
                        $el = $this->describe((int) ($d['id'] ?? -1));
                        $click = [
                            'at_s'    => $at($e),
                            'event'   => $mi === self::MI_DBLCLICK ? 'dblclick' : ($mi === self::MI_TOUCHEND ? 'tap' : 'click'),
                            'element' => $el,
                            'x'       => $d['x'] ?? null,
                            'y'       => $d['y'] ?? null,
                            '_ts'     => (int) ($e['timestamp'] ?? 0),
                        ];
                        $clicks[] = $click;
                        unset($click['_ts']);
                        $timeline[] = $click;
                    }
                    break;

                case self::SRC_SCROLL:
                    $counts['scroll_events']++;
                    $id = (int) ($d['id'] ?? -1);
                    // id 1 is usually the document; also accept the <html>/<body> node
                    if ($curPage !== null && isset($d['y']) && ($id === 1 || in_array($this->nodes[$id]['tag'] ?? '', ['html', 'body', '#document'], true))) {
                        $pages[$curPage]['max_scroll_y'] = max($pages[$curPage]['max_scroll_y'], (int) $d['y']);
                    }
                    break;

                case self::SRC_RESIZE:
                    $viewport = ($d['width'] ?? '?') . 'x' . ($d['height'] ?? '?');
                    $timeline[] = ['at_s' => $at($e), 'event' => 'resize', 'viewport' => $viewport];
                    break;

                case self::SRC_INPUT:
                    $id = (int) ($d['id'] ?? -1);
                    if ($id < 0 || strtolower($this->nodes[$id]['attrs']['type'] ?? '') === 'hidden') {
                        break;
                    }
                    // Raw input events are filtered/merged after the loop (see filterInputs)
                    $entry = ['at_s' => $at($e), 'event' => 'input', 'field' => $this->describe($id), '_id' => $id, '_ts' => (int) $e['timestamp']];
                    if (array_key_exists('isChecked', $d)) {
                        $entry['checked'] = (bool) $d['isChecked'];
                    }
                    $inputs[] = $entry;
                    break;

                case self::SRC_LOG:
                    if (in_array($d['level'] ?? '', ['error', 'warn'], true)) {
                        $timeline[] = ['at_s' => $at($e), 'event' => 'console_' . $d['level'], 'message' => self::short(implode(' ', (array) ($d['payload'] ?? [])), 200)];
                    }
                    break;
            }
        }

        $rage = self::rageClicks($clicks, $t0);

        [$inputs, $ignoredInputs] = self::filterInputs($inputs);
        foreach ($inputs as $in) {
            unset($in['_id'], $in['_ts']);
            $timeline[] = $in;
        }
        usort($timeline, fn($a, $b) => $a['at_s'] <=> $b['at_s']);

        $truncated = count($timeline) > $maxTimeline;
        if ($truncated) {
            $timeline = array_slice($timeline, 0, $maxTimeline);
        }

        return [
            'duration_s'       => round(($tEnd - $t0) / 1000, 1),
            'started_at_utc'   => $t0 ? gmdate('Y-m-d H:i:s', intdiv($t0, 1000)) : null,
            'raw_event_count'  => count($events),
            'pages'            => array_values(array_map(fn($p) => [
                'url'            => $p['url'],
                'first_at_s'     => $p['first_at_s'],
                'max_scroll_y_px' => $p['max_scroll_y'],
            ], $pages)),
            'clicks_total'     => count($clicks),
            'rage_clicks'      => $rage,
            'fields_interacted' => array_values(array_map(
                fn($j) => json_decode($j, true) ?? $j,
                array_unique(array_map(fn($i) => json_encode($i['field'], JSON_UNESCAPED_UNICODE), $inputs))
            )),
            'activity_counts'  => $counts + ['programmatic_inputs_ignored' => $ignoredInputs],
            'timeline'         => $timeline,
            'timeline_truncated' => $truncated,
            'note'             => 'Input values are intentionally not returned. at_s = seconds from replay start.',
        ];
    }

    /**
     * Drops script-generated input events (e.g. Meta Pixel fills ~60 hidden fields at once):
     * any 200 ms window touching more than 2 distinct fields is treated as programmatic.
     * Remaining keystrokes on the same field within 5 s are merged into one entry.
     * @return array{0: array, 1: int} [kept inputs, ignored count]
     */
    private static function filterInputs(array $inputs): array
    {
        $n = count($inputs);
        $drop = array_fill(0, $n, false);
        for ($i = 0; $i < $n; $i++) {
            $fields = [];
            for ($j = $i; $j < $n && $inputs[$j]['_ts'] - $inputs[$i]['_ts'] <= 200; $j++) {
                $fields[$inputs[$j]['_id']] = true;
            }
            if (count($fields) > 2) {
                for ($k = $i; $k < $j; $k++) {
                    $drop[$k] = true;
                }
            }
        }

        $kept = [];
        $ignored = 0;
        foreach ($inputs as $i => $in) {
            if ($drop[$i]) {
                $ignored++;
                continue;
            }
            $lastKey = array_key_last($kept);
            if ($lastKey !== null && $kept[$lastKey]['_id'] === $in['_id'] && $in['_ts'] - $kept[$lastKey]['_ts'] < 5000) {
                $kept[$lastKey]['_ts'] = $in['_ts'];
                continue;
            }
            $kept[] = $in;
        }
        return [$kept, $ignored];
    }

    /** Removes click IDs, Google Ads ValueTrack noise and empty params; keeps utm_* and meaningful params. */
    public static function cleanUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!$parts || empty($parts['query'])) {
            return $url;
        }
        parse_str($parts['query'], $q);
        $noise = ['gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid', 'ttclid', 'yclid', 'srsltid', 'gad_source', 'gad_campaignid',
                  '_gl', 'mc_eid', 'loc_interest_ms', 'loc_physical_ms', 'feeditemid', 'targetid', 'devicemodel', 'ifmobile',
                  'ifnotmobile', 'ifsearch', 'ifcontent:', 'network', 'device', 'creative', 'placement', 'target', 'matchtype', 'adgroup'];
        $q = array_filter($q, fn($v, $k) => $v !== '' && $v !== null && !in_array(strtolower((string) $k), $noise, true)
            && !str_starts_with((string) $k, 'ifcontent'), ARRAY_FILTER_USE_BOTH);
        $base = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . ($parts['path'] ?? '/');
        return $q ? $base . '?' . http_build_query($q) : $base;
    }

    /** ≥3 clicks within 1 s inside a 40px radius. */
    private static function rageClicks(array $clicks, int $t0): array
    {
        $out = [];
        $n = count($clicks);
        for ($i = 0; $i < $n; $i++) {
            $group = [$clicks[$i]];
            for ($j = $i + 1; $j < $n; $j++) {
                if ($clicks[$j]['_ts'] - $clicks[$i]['_ts'] > 1000) {
                    break;
                }
                $dx = ($clicks[$j]['x'] ?? 0) - ($clicks[$i]['x'] ?? 0);
                $dy = ($clicks[$j]['y'] ?? 0) - ($clicks[$i]['y'] ?? 0);
                if (sqrt($dx * $dx + $dy * $dy) <= 40) {
                    $group[] = $clicks[$j];
                }
            }
            if (count($group) >= 3) {
                $out[] = [
                    'at_s'    => round(($clicks[$i]['_ts'] - $t0) / 1000, 1),
                    'clicks'  => count($group),
                    'element' => $clicks[$i]['element'],
                ];
                $i += count($group) - 1;
            }
        }
        return $out;
    }

    private function indexNode(array $node, ?int $parentId): void
    {
        $id = isset($node['id']) ? (int) $node['id'] : null;
        if ($id !== null) {
            if ($parentId !== null) {
                $this->parent[$id] = $parentId;
            }
            $nodeType = $node['type'] ?? null;
            if ($nodeType === 2) { // Element
                $attrs = $node['attributes'] ?? [];
                $keep  = [];
                foreach (['id', 'class', 'href', 'name', 'type', 'role', 'aria-label', 'title', 'alt', 'placeholder', 'value'] as $k) {
                    if (isset($attrs[$k]) && is_string($attrs[$k]) && $attrs[$k] !== '') {
                        // never keep field values; keep button/submit labels only
                        if ($k === 'value' && !in_array(strtolower((string) ($attrs['type'] ?? '')), ['submit', 'button'], true)) {
                            continue;
                        }
                        $keep[$k] = self::short($attrs[$k], 120);
                    }
                }
                $this->nodes[$id] = ['tag' => strtolower((string) ($node['tagName'] ?? '?')), 'attrs' => $keep, 'text' => ''];
            } elseif ($nodeType === 3) { // Text
                $text = trim(preg_replace('/\s+/u', ' ', (string) ($node['textContent'] ?? '')));
                if ($text !== '' && $parentId !== null && isset($this->nodes[$parentId])
                    && !in_array($this->nodes[$parentId]['tag'], ['script', 'style'], true)) {
                    $this->appendText($parentId, $text);
                }
            } elseif ($nodeType === 0) {
                $this->nodes[$id] = ['tag' => '#document', 'attrs' => [], 'text' => ''];
            }
        }
        foreach ($node['childNodes'] ?? [] as $child) {
            $this->indexNode($child, $id);
        }
    }

    /** Propagates text up to 3 ancestors so a click on <span> inside <button> still shows the label. */
    private function appendText(int $id, string $text): void
    {
        for ($depth = 0; $depth < 4 && $id !== null && isset($this->nodes[$id]); $depth++) {
            if (mb_strlen($this->nodes[$id]['text']) < 80) {
                $this->nodes[$id]['text'] = self::short(trim($this->nodes[$id]['text'] . ' ' . $text), 80);
            }
            $id = $this->parent[$id] ?? null;
        }
    }

    /** Describes the element, climbing to the nearest interactive ancestor (a, button, input, label…). */
    private function describe(int $id): array|string
    {
        // Clicks often land on a text node — use its parent element
        if (!isset($this->nodes[$id]) && isset($this->parent[$id], $this->nodes[$this->parent[$id]])) {
            $id = $this->parent[$id];
        }
        if (!isset($this->nodes[$id])) {
            return "node#$id (not in snapshot)";
        }
        $interactive = ['a', 'button', 'input', 'select', 'textarea', 'label', 'summary', 'option'];
        $cur = $id;
        for ($i = 0; $i < 5; $i++) {
            if (in_array($this->nodes[$cur]['tag'] ?? '', $interactive, true)
                || in_array($this->nodes[$cur]['attrs']['role'] ?? '', ['button', 'link', 'tab', 'menuitem', 'checkbox'], true)) {
                $id = $cur;
                break;
            }
            $p = $this->parent[$cur] ?? null;
            if ($p === null || !isset($this->nodes[$p])) {
                break;
            }
            $cur = $p;
        }

        $n   = $this->nodes[$id];
        $out = ['tag' => $n['tag']];
        if ($n['text'] !== '') {
            $out['text'] = $n['text'];
        }
        return $out + $n['attrs'];
    }

    private static function short(string $s, int $max): string
    {
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
    }
}
