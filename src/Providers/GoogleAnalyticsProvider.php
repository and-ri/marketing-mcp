<?php

use Google\Analytics\Data\V1beta\Client\BetaAnalyticsDataClient;
use Google\Analytics\Data\V1beta\RunReportRequest;
use Google\Analytics\Data\V1beta\RunRealtimeReportRequest;
use Google\Analytics\Data\V1beta\DateRange;
use Google\Analytics\Data\V1beta\Dimension;
use Google\Analytics\Data\V1beta\Metric;
use Google\Analytics\Data\V1beta\OrderBy;
use Google\Analytics\Data\V1beta\OrderBy\MetricOrderBy;
use Google\Auth\Credentials\UserRefreshCredentials;

class GoogleAnalyticsProvider
{
    private array $config;
    private ?BetaAnalyticsDataClient $client = null;

    public function __construct(array $config = [])
    {
        $this->config = $config ?: $_ENV;
    }

    private function client(): BetaAnalyticsDataClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $credentials = new UserRefreshCredentials(
            'https://www.googleapis.com/auth/analytics.readonly',
            [
                'client_id'     => $this->config['GOOGLE_CLIENT_ID'],
                'client_secret' => $this->config['GOOGLE_CLIENT_SECRET'],
                'refresh_token' => $this->config['GOOGLE_REFRESH_TOKEN'],
            ]
        );

        $this->client = new BetaAnalyticsDataClient(['credentials' => $credentials]);
        return $this->client;
    }

    private function runReport(string $propertyId, array $dimensions, array $metrics, string $startDate, string $endDate, int $limit = 100, ?OrderBy $orderBy = null): array
    {
        $request = (new RunReportRequest())
            ->setProperty("properties/$propertyId")
            ->setDateRanges([new DateRange(['start_date' => $startDate, 'end_date' => $endDate])])
            ->setDimensions(array_map(fn($n) => new Dimension(['name' => $n]), $dimensions))
            ->setMetrics(array_map(fn($n) => new Metric(['name' => $n]), $metrics))
            ->setLimit($limit);

        if ($orderBy !== null) {
            $request->setOrderBys([$orderBy]);
        }

        $response = $this->client()->runReport($request);
        return $this->parseReport($response, $dimensions, $metrics);
    }

    private function parseReport(mixed $response, array $dimensions, array $metrics): array
    {
        $rows = [];
        foreach ($response->getRows() as $row) {
            $entry = [];
            foreach ($row->getDimensionValues() as $i => $v) {
                $entry[$dimensions[$i] ?? "dim_$i"] = $v->getValue();
            }
            foreach ($row->getMetricValues() as $i => $v) {
                $entry[$metrics[$i] ?? "metric_$i"] = $v->getValue();
            }
            $rows[] = $entry;
        }
        $rowCount = method_exists($response, 'getRowCount') ? $response->getRowCount() : count($rows);
        $sampled  = method_exists($response, 'getMetadata')
            ? ($response->getMetadata()?->getDataLossFromOtherRow() ?? false)
            : false;

        return [
            'rows'      => $rows,
            'total'     => count($rows),
            'row_count' => $rowCount,
            'sampled'   => $sampled,
        ];
    }

    public function registerTools(McpServer $server): void
    {
        $server->registerTool(
            'ga4_run_report',
            'Run a custom Google Analytics 4 report with any dimensions and metrics.',
            [
                'type'       => 'object',
                'properties' => [
                    'property_id' => ['type' => 'string', 'description' => 'GA4 property ID (numeric, without "properties/" prefix)'],
                    'dimensions'  => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Dimension names, e.g. ["date", "sessionSource", "country"]'],
                    'metrics'     => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Metric names, e.g. ["sessions", "totalUsers", "bounceRate"]'],
                    'start_date'  => ['type' => 'string', 'description' => 'Start date: YYYY-MM-DD or "7daysAgo", "30daysAgo", "yesterday"', 'default' => '30daysAgo'],
                    'end_date'    => ['type' => 'string', 'description' => 'End date: YYYY-MM-DD or "today", "yesterday"', 'default' => 'today'],
                    'limit'       => ['type' => 'integer', 'default' => 100],
                ],
                'required' => ['property_id', 'dimensions', 'metrics'],
            ],
            [$this, 'customReport']
        );

        $server->registerTool(
            'ga4_run_realtime_report',
            'Get real-time active users and events in Google Analytics 4.',
            [
                'type'       => 'object',
                'properties' => [
                    'property_id' => ['type' => 'string', 'description' => 'GA4 property ID'],
                    'dimensions'  => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Dimensions, e.g. ["country", "unifiedScreenName"]', 'default' => ['country']],
                    'metrics'     => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Metrics, e.g. ["activeUsers"]', 'default' => ['activeUsers']],
                ],
                'required' => ['property_id'],
            ],
            [$this, 'realtimeReport']
        );

        $server->registerTool(
            'ga4_get_audience_overview',
            'Get audience overview: users, sessions, bounce rate, session duration, new vs returning.',
            [
                'type'       => 'object',
                'properties' => [
                    'property_id' => ['type' => 'string', 'description' => 'GA4 property ID'],
                    'start_date'  => ['type' => 'string', 'default' => '30daysAgo'],
                    'end_date'    => ['type' => 'string', 'default' => 'today'],
                ],
                'required' => ['property_id'],
            ],
            [$this, 'audienceOverview']
        );

        $server->registerTool(
            'ga4_get_traffic_sources',
            'Get traffic breakdown by source, medium, and channel group with session metrics.',
            [
                'type'       => 'object',
                'properties' => [
                    'property_id' => ['type' => 'string', 'description' => 'GA4 property ID'],
                    'start_date'  => ['type' => 'string', 'default' => '30daysAgo'],
                    'end_date'    => ['type' => 'string', 'default' => 'today'],
                    'limit'       => ['type' => 'integer', 'default' => 50],
                ],
                'required' => ['property_id'],
            ],
            [$this, 'trafficSources']
        );

        $server->registerTool(
            'ga4_get_top_pages',
            'Get top pages and screens by views, users, and engagement metrics.',
            [
                'type'       => 'object',
                'properties' => [
                    'property_id' => ['type' => 'string', 'description' => 'GA4 property ID'],
                    'start_date'  => ['type' => 'string', 'default' => '30daysAgo'],
                    'end_date'    => ['type' => 'string', 'default' => 'today'],
                    'limit'       => ['type' => 'integer', 'default' => 50],
                ],
                'required' => ['property_id'],
            ],
            [$this, 'topPages']
        );

        $server->registerTool(
            'ga4_get_conversions',
            'Get conversion events with counts and revenue values.',
            [
                'type'       => 'object',
                'properties' => [
                    'property_id' => ['type' => 'string', 'description' => 'GA4 property ID'],
                    'start_date'  => ['type' => 'string', 'default' => '30daysAgo'],
                    'end_date'    => ['type' => 'string', 'default' => 'today'],
                ],
                'required' => ['property_id'],
            ],
            [$this, 'conversions']
        );

        $server->registerTool(
            'ga4_get_ecommerce',
            'Get e-commerce metrics: revenue, transactions, items sold, average order value.',
            [
                'type'       => 'object',
                'properties' => [
                    'property_id' => ['type' => 'string', 'description' => 'GA4 property ID'],
                    'start_date'  => ['type' => 'string', 'default' => '30daysAgo'],
                    'end_date'    => ['type' => 'string', 'default' => 'today'],
                    'limit'       => ['type' => 'integer', 'default' => 50],
                ],
                'required' => ['property_id'],
            ],
            [$this, 'ecommerce']
        );

        $server->registerTool(
            'ga4_get_geo_breakdown',
            'Get traffic and conversions broken down by country and city.',
            [
                'type'       => 'object',
                'properties' => [
                    'property_id' => ['type' => 'string', 'description' => 'GA4 property ID'],
                    'start_date'  => ['type' => 'string', 'default' => '30daysAgo'],
                    'end_date'    => ['type' => 'string', 'default' => 'today'],
                    'limit'       => ['type' => 'integer', 'default' => 50],
                ],
                'required' => ['property_id'],
            ],
            [$this, 'geoBreakdown']
        );

        $server->registerTool(
            'ga4_get_device_breakdown',
            'Get traffic breakdown by device category (desktop, mobile, tablet).',
            [
                'type'       => 'object',
                'properties' => [
                    'property_id' => ['type' => 'string', 'description' => 'GA4 property ID'],
                    'start_date'  => ['type' => 'string', 'default' => '30daysAgo'],
                    'end_date'    => ['type' => 'string', 'default' => 'today'],
                ],
                'required' => ['property_id'],
            ],
            [$this, 'deviceBreakdown']
        );
    }

    public function customReport(array $args): array
    {
        return $this->runReport(
            $args['property_id'],
            $args['dimensions'],
            $args['metrics'],
            $args['start_date'] ?? '30daysAgo',
            $args['end_date'] ?? 'today',
            $args['limit'] ?? 100
        );
    }

    public function realtimeReport(array $args): array
    {
        $dimensions = $args['dimensions'] ?? ['country'];
        $metrics    = $args['metrics'] ?? ['activeUsers'];

        $request = (new RunRealtimeReportRequest())
            ->setProperty("properties/{$args['property_id']}")
            ->setDimensions(array_map(fn($n) => new Dimension(['name' => $n]), $dimensions))
            ->setMetrics(array_map(fn($n) => new Metric(['name' => $n]), $metrics));

        $response = $this->client()->runRealtimeReport($request);
        return $this->parseReport($response, $dimensions, $metrics);
    }

    public function audienceOverview(array $args): array
    {
        return $this->runReport(
            $args['property_id'],
            ['date', 'newVsReturning'],
            ['totalUsers', 'newUsers', 'sessions', 'bounceRate', 'averageSessionDuration', 'screenPageViewsPerSession', 'engagedSessions', 'engagementRate'],
            $args['start_date'] ?? '30daysAgo',
            $args['end_date'] ?? 'today',
            500
        );
    }

    public function trafficSources(array $args): array
    {
        return $this->runReport(
            $args['property_id'],
            ['sessionDefaultChannelGroup', 'sessionSource', 'sessionMedium'],
            ['sessions', 'totalUsers', 'newUsers', 'bounceRate', 'averageSessionDuration', 'conversions', 'totalRevenue'],
            $args['start_date'] ?? '30daysAgo',
            $args['end_date'] ?? 'today',
            $args['limit'] ?? 50
        );
    }

    public function topPages(array $args): array
    {
        return $this->runReport(
            $args['property_id'],
            ['pagePath', 'pageTitle'],
            ['screenPageViews', 'totalUsers', 'averageSessionDuration', 'bounceRate', 'engagementRate', 'conversions'],
            $args['start_date'] ?? '30daysAgo',
            $args['end_date'] ?? 'today',
            $args['limit'] ?? 50
        );
    }

    public function conversions(array $args): array
    {
        return $this->runReport(
            $args['property_id'],
            ['eventName', 'sessionDefaultChannelGroup'],
            ['conversions', 'totalRevenue', 'sessions'],
            $args['start_date'] ?? '30daysAgo',
            $args['end_date'] ?? 'today',
            200
        );
    }

    public function ecommerce(array $args): array
    {
        return $this->runReport(
            $args['property_id'],
            ['date', 'itemName'],
            ['totalRevenue', 'transactions', 'itemsPurchased', 'itemRevenue', 'averagePurchaseRevenue', 'purchaseToViewRate'],
            $args['start_date'] ?? '30daysAgo',
            $args['end_date'] ?? 'today',
            $args['limit'] ?? 50
        );
    }

    public function geoBreakdown(array $args): array
    {
        return $this->runReport(
            $args['property_id'],
            ['country', 'city'],
            ['sessions', 'totalUsers', 'newUsers', 'bounceRate', 'conversions', 'totalRevenue'],
            $args['start_date'] ?? '30daysAgo',
            $args['end_date'] ?? 'today',
            $args['limit'] ?? 50
        );
    }

    public function deviceBreakdown(array $args): array
    {
        return $this->runReport(
            $args['property_id'],
            ['deviceCategory', 'operatingSystem', 'browser'],
            ['sessions', 'totalUsers', 'bounceRate', 'engagementRate', 'conversions', 'averageSessionDuration'],
            $args['start_date'] ?? '30daysAgo',
            $args['end_date'] ?? 'today',
            50
        );
    }
}
