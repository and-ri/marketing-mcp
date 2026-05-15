<?php

class TikTokAdsProvider
{
    private const BASE = 'https://business-api.tiktok.com/open_api/v1.3';

    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config ?: $_ENV;
    }

    private function token(): string
    {
        return $this->config['TIKTOK_ACCESS_TOKEN'] ?? '';
    }

    private function get(string $path, array $params = []): array
    {
        // Non-scalar values must be JSON-encoded as strings in the query string
        $encoded = [];
        foreach ($params as $k => $v) {
            $encoded[$k] = is_string($v) ? $v : json_encode($v);
        }
        $url = self::BASE . $path . ($encoded ? '?' . http_build_query($encoded) : '');
        $ctx = stream_context_create(['http' => [
            'method'        => 'GET',
            'header'        => "Access-Token: {$this->token()}\r\n",
            'ignore_errors' => true,
        ]]);
        return json_decode((string) file_get_contents($url, false, $ctx), true) ?: [];
    }

    private function post(string $path, array $payload): array
    {
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Access-Token: {$this->token()}\r\nContent-Type: application/json\r\n",
            'content'       => json_encode($payload),
            'ignore_errors' => true,
        ]]);
        return json_decode((string) file_get_contents(self::BASE . $path, false, $ctx), true) ?: [];
    }

    private function unwrap(array $response): array
    {
        $code = $response['code'] ?? -1;
        if ($code !== 0) {
            throw new \RuntimeException(
                'TikTok API error ' . $code . ': ' . ($response['message'] ?? json_encode($response))
            );
        }
        return $response['data'] ?? [];
    }

    public function registerTools(McpServer $server): void
    {
        $server->registerTool(
            'tiktok_get_advertisers',
            'List all TikTok Ads advertiser accounts accessible with the current token.',
            [
                'type'       => 'object',
                'properties' => new stdClass(),
                'required'   => [],
            ],
            [$this, 'getAdvertisers']
        );

        $server->registerTool(
            'tiktok_get_campaigns',
            'Get TikTok Ads campaigns for an advertiser with status and budget.',
            [
                'type'       => 'object',
                'properties' => [
                    'advertiser_id' => ['type' => 'string', 'description' => 'TikTok Ads advertiser ID'],
                    'status'        => [
                        'type'        => 'string',
                        'description' => 'Filter by status: STATUS_ALL, STATUS_DELIVERY_OK, STATUS_DISABLE, STATUS_DELETE',
                        'default'     => 'STATUS_ALL',
                    ],
                    'page_size' => ['type' => 'integer', 'default' => 50],
                ],
                'required' => ['advertiser_id'],
            ],
            [$this, 'getCampaigns']
        );

        $server->registerTool(
            'tiktok_get_ad_groups',
            'Get TikTok Ads ad groups for an advertiser or specific campaigns.',
            [
                'type'       => 'object',
                'properties' => [
                    'advertiser_id' => ['type' => 'string', 'description' => 'TikTok Ads advertiser ID'],
                    'campaign_ids'  => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Optional: filter by campaign IDs'],
                    'status'        => [
                        'type'        => 'string',
                        'description' => 'Filter by status: STATUS_ALL, STATUS_DELIVERY_OK, STATUS_DISABLE, STATUS_DELETE',
                        'default'     => 'STATUS_ALL',
                    ],
                    'page_size' => ['type' => 'integer', 'default' => 50],
                ],
                'required' => ['advertiser_id'],
            ],
            [$this, 'getAdGroups']
        );

        $server->registerTool(
            'tiktok_get_ads',
            'Get TikTok individual ads with creative details and status.',
            [
                'type'       => 'object',
                'properties' => [
                    'advertiser_id' => ['type' => 'string', 'description' => 'TikTok Ads advertiser ID'],
                    'campaign_ids'  => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Optional: filter by campaign IDs'],
                    'adgroup_ids'   => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Optional: filter by ad group IDs'],
                    'status'        => [
                        'type'        => 'string',
                        'description' => 'Filter by status: STATUS_ALL, STATUS_DELIVERY_OK, STATUS_DISABLE, STATUS_DELETE',
                        'default'     => 'STATUS_ALL',
                    ],
                    'page_size' => ['type' => 'integer', 'default' => 50],
                ],
                'required' => ['advertiser_id'],
            ],
            [$this, 'getAds']
        );

        $server->registerTool(
            'tiktok_get_report',
            'Get TikTok Ads performance report at campaign, adgroup, or ad level. Use campaign_ids/adgroup_ids/ad_ids to scope to specific entities.',
            [
                'type'       => 'object',
                'properties' => [
                    'advertiser_id' => ['type' => 'string', 'description' => 'TikTok Ads advertiser ID'],
                    'level'         => ['type' => 'string', 'description' => 'Report level: campaign, adgroup, ad', 'default' => 'campaign'],
                    'start_date'    => ['type' => 'string', 'description' => 'Start date YYYY-MM-DD'],
                    'end_date'      => ['type' => 'string', 'description' => 'End date YYYY-MM-DD'],
                    'campaign_ids'  => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Optional: scope to specific campaign IDs'],
                    'adgroup_ids'   => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Optional: scope to specific ad group IDs'],
                    'ad_ids'        => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Optional: scope to specific ad IDs'],
                    'daily'         => ['type' => 'boolean', 'description' => 'Break results down by day', 'default' => false],
                    'include_video' => ['type' => 'boolean', 'description' => 'Include video engagement metrics (views, watch time, completion rates)', 'default' => false],
                    'page_size'     => ['type' => 'integer', 'default' => 100],
                ],
                'required' => ['advertiser_id', 'start_date', 'end_date'],
            ],
            [$this, 'getReport']
        );
    }

    public function getAdvertisers(array $args): array
    {
        $data = $this->unwrap(
            $this->get('/oauth2/advertiser/get/', [
                'app_id' => $this->config['TIKTOK_APP_ID'] ?? '',
                'secret' => $this->config['TIKTOK_APP_SECRET'] ?? '',
            ])
        );
        return ['advertisers' => $data['list'] ?? [], 'total' => count($data['list'] ?? [])];
    }

    public function getCampaigns(array $args): array
    {
        $filtering = [];
        $status    = $args['status'] ?? 'STATUS_ALL';
        if ($status !== 'STATUS_ALL') {
            $filtering['primary_status'] = $status;
        }

        $params = [
            'advertiser_id' => $args['advertiser_id'],
            'page_size'     => $args['page_size'] ?? 50,
        ];
        if ($filtering) {
            $params['filtering'] = $filtering; // encoded in get() automatically
        }

        $data = $this->unwrap($this->get('/campaign/get/', $params));
        return [
            'campaigns' => $data['list'] ?? [],
            'total'     => $data['page_info']['total_number'] ?? count($data['list'] ?? []),
        ];
    }

    public function getAdGroups(array $args): array
    {
        $filtering = [];
        $status    = $args['status'] ?? 'STATUS_ALL';
        if ($status !== 'STATUS_ALL') {
            $filtering['primary_status'] = $status;
        }
        if (!empty($args['campaign_ids'])) {
            $filtering['campaign_ids'] = $args['campaign_ids'];
        }

        $params = [
            'advertiser_id' => $args['advertiser_id'],
            'page_size'     => $args['page_size'] ?? 50,
        ];
        if ($filtering) {
            $params['filtering'] = $filtering;
        }

        $data = $this->unwrap($this->get('/adgroup/get/', $params));
        return [
            'ad_groups' => $data['list'] ?? [],
            'total'     => $data['page_info']['total_number'] ?? count($data['list'] ?? []),
        ];
    }

    public function getAds(array $args): array
    {
        $filtering = [];
        $status    = $args['status'] ?? 'STATUS_ALL';
        if ($status !== 'STATUS_ALL') {
            $filtering['primary_status'] = $status;
        }
        if (!empty($args['campaign_ids'])) {
            $filtering['campaign_ids'] = $args['campaign_ids'];
        }
        if (!empty($args['adgroup_ids'])) {
            $filtering['adgroup_ids'] = $args['adgroup_ids'];
        }

        $params = [
            'advertiser_id' => $args['advertiser_id'],
            'page_size'     => $args['page_size'] ?? 50,
        ];
        if ($filtering) {
            $params['filtering'] = $filtering;
        }

        $data = $this->unwrap($this->get('/ad/get/', $params));
        return [
            'ads'   => $data['list'] ?? [],
            'total' => $data['page_info']['total_number'] ?? count($data['list'] ?? []),
        ];
    }

    public function getReport(array $args): array
    {
        $level    = $args['level'] ?? 'campaign';
        $daily    = !empty($args['daily']);
        $pageSize = $args['page_size'] ?? 100;

        $dataLevel = match ($level) {
            'adgroup' => 'AUCTION_ADGROUP',
            'ad'      => 'AUCTION_AD',
            default   => 'AUCTION_CAMPAIGN',
        };

        $dimensions = match ($level) {
            'adgroup' => ['adgroup_id'],
            'ad'      => ['ad_id'],
            default   => ['campaign_id'],
        };
        if ($daily) {
            $dimensions[] = 'stat_time_day';
        }

        $metrics = [
            'spend', 'cpc', 'cpm', 'impressions', 'clicks', 'ctr',
            'reach', 'frequency',
            'conversion', 'cost_per_conversion', 'conversion_rate_v2',
            'result', 'cost_per_result', 'result_rate',
        ];
        if (!empty($args['include_video'])) {
            array_push($metrics,
                'video_play_actions',
                'video_watched_2s',
                'video_watched_6s',
                'average_video_play',
                'average_video_play_per_user',
                'video_views_p25',
                'video_views_p50',
                'video_views_p75',
                'video_views_p100'
            );
        }

        // Report uses array-of-objects filtering format
        $filtering = [];
        if (!empty($args['campaign_ids'])) {
            $filtering[] = [
                'field_name'   => 'campaign_ids',
                'filter_type'  => 'IN',
                'filter_value' => json_encode($args['campaign_ids']),
            ];
        }
        if (!empty($args['adgroup_ids'])) {
            $filtering[] = [
                'field_name'   => 'adgroup_ids',
                'filter_type'  => 'IN',
                'filter_value' => json_encode($args['adgroup_ids']),
            ];
        }
        if (!empty($args['ad_ids'])) {
            $filtering[] = [
                'field_name'   => 'ad_ids',
                'filter_type'  => 'IN',
                'filter_value' => json_encode($args['ad_ids']),
            ];
        }

        $params = [
            'advertiser_id'        => $args['advertiser_id'],
            'report_type'          => 'BASIC',
            'data_level'           => $dataLevel,
            'dimensions'           => $dimensions,  // encoded in get() automatically
            'metrics'              => $metrics,
            'start_date'           => $args['start_date'],
            'end_date'             => $args['end_date'],
            'enable_total_metrics' => true,
            'page_size'            => $pageSize,
        ];
        if ($filtering) {
            $params['filtering'] = $filtering;
        }

        $data = $this->unwrap($this->get('/report/integrated/get/', $params));
        return [
            'report'     => $data['list'] ?? [],
            'total'      => $data['page_info']['total_number'] ?? count($data['list'] ?? []),
            'level'      => $level,
            'daily'      => $daily,
            'start_date' => $args['start_date'],
            'end_date'   => $args['end_date'],
        ];
    }
}
