<?php

use Google\Ads\GoogleAds\Lib\V24\GoogleAdsClientBuilder;
use Google\Ads\GoogleAds\Lib\OAuth2TokenBuilder;
use Google\Ads\GoogleAds\V24\Services\ListAccessibleCustomersRequest;
use Google\Ads\GoogleAds\V24\Services\SearchGoogleAdsStreamRequest;

class GoogleAdsProvider
{
    private array $config;
    private mixed $client = null;

    public function __construct(array $config = [])
    {
        $this->config = $config ?: $_ENV;
    }

    private function client(): mixed
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $oAuth2Credential = (new OAuth2TokenBuilder())
            ->withClientId($this->config['GOOGLE_ADS_CLIENT_ID'])
            ->withClientSecret($this->config['GOOGLE_ADS_CLIENT_SECRET'])
            ->withRefreshToken($this->config['GOOGLE_ADS_REFRESH_TOKEN'])
            ->build();

        $builder = (new GoogleAdsClientBuilder())
            ->withDeveloperToken($this->config['GOOGLE_ADS_DEVELOPER_TOKEN'])
            ->withOAuth2Credential($oAuth2Credential);

        if (!empty($this->config['GOOGLE_ADS_LOGIN_CUSTOMER_ID'])) {
            $builder = $builder->withLoginCustomerId((int) $this->config['GOOGLE_ADS_LOGIN_CUSTOMER_ID']);
        }

        $this->client = $builder->build();
        return $this->client;
    }

    private function gaql(string $customerId, string $query): array
    {
        $request = new SearchGoogleAdsStreamRequest([
            'customer_id' => $customerId,
            'query'       => $query,
        ]);
        $stream = $this->client()
            ->getGoogleAdsServiceClient()
            ->searchStream($request);

        $rows = [];
        foreach ($stream->iterateAllElements() as $row) {
            $rows[] = json_decode($row->serializeToJsonString(), true);
        }
        return $rows;
    }

    public function registerTools(McpServer $server): void
    {
        $server->registerTool(
            'google_ads_list_customers',
            'List all accessible Google Ads customer accounts (accounts you can manage).',
            [
                'type'       => 'object',
                'properties' => [],
                'required'   => [],
            ],
            [$this, 'listCustomers']
        );

        $server->registerTool(
            'google_ads_get_campaigns',
            'Get campaigns for a Google Ads customer account with performance metrics.',
            [
                'type'       => 'object',
                'properties' => [
                    'customer_id' => ['type' => 'string', 'description' => 'Google Ads customer ID (without dashes)'],
                    'date_range'  => ['type' => 'string', 'description' => 'Date range, e.g. "LAST_30_DAYS", "LAST_7_DAYS", "THIS_MONTH", "LAST_MONTH"', 'default' => 'LAST_30_DAYS'],
                    'status'      => ['type' => 'string', 'description' => 'Filter by status: ENABLED, PAUSED, REMOVED, or ALL', 'default' => 'ALL'],
                ],
                'required' => ['customer_id'],
            ],
            [$this, 'getCampaigns']
        );

        $server->registerTool(
            'google_ads_get_ad_groups',
            'Get ad groups for a customer or specific campaign with metrics.',
            [
                'type'       => 'object',
                'properties' => [
                    'customer_id' => ['type' => 'string', 'description' => 'Google Ads customer ID'],
                    'campaign_id' => ['type' => 'string', 'description' => 'Optional: filter by specific campaign ID'],
                    'date_range'  => ['type' => 'string', 'default' => 'LAST_30_DAYS'],
                ],
                'required' => ['customer_id'],
            ],
            [$this, 'getAdGroups']
        );

        $server->registerTool(
            'google_ads_get_keywords',
            'Get keywords with quality score, bids, and performance metrics.',
            [
                'type'       => 'object',
                'properties' => [
                    'customer_id'  => ['type' => 'string', 'description' => 'Google Ads customer ID'],
                    'campaign_id'  => ['type' => 'string', 'description' => 'Optional: filter by campaign ID'],
                    'ad_group_id'  => ['type' => 'string', 'description' => 'Optional: filter by ad group ID'],
                    'date_range'   => ['type' => 'string', 'default' => 'LAST_30_DAYS'],
                    'limit'        => ['type' => 'integer', 'description' => 'Max rows to return', 'default' => 200],
                ],
                'required' => ['customer_id'],
            ],
            [$this, 'getKeywords']
        );

        $server->registerTool(
            'google_ads_get_search_terms',
            'Get search terms report — what users actually searched to trigger your ads.',
            [
                'type'       => 'object',
                'properties' => [
                    'customer_id' => ['type' => 'string', 'description' => 'Google Ads customer ID'],
                    'campaign_id' => ['type' => 'string', 'description' => 'Optional: filter by campaign ID'],
                    'date_range'  => ['type' => 'string', 'default' => 'LAST_30_DAYS'],
                    'limit'       => ['type' => 'integer', 'default' => 200],
                ],
                'required' => ['customer_id'],
            ],
            [$this, 'getSearchTerms']
        );

        $server->registerTool(
            'google_ads_get_ads',
            'Get individual ads with creative details and performance metrics.',
            [
                'type'       => 'object',
                'properties' => [
                    'customer_id'  => ['type' => 'string', 'description' => 'Google Ads customer ID'],
                    'campaign_id'  => ['type' => 'string', 'description' => 'Optional: filter by campaign ID'],
                    'ad_group_id'  => ['type' => 'string', 'description' => 'Optional: filter by ad group ID'],
                    'date_range'   => ['type' => 'string', 'default' => 'LAST_30_DAYS'],
                    'limit'        => ['type' => 'integer', 'default' => 100],
                ],
                'required' => ['customer_id'],
            ],
            [$this, 'getAds']
        );

        $server->registerTool(
            'google_ads_get_geo_performance',
            'Get geographic performance breakdown (country, region, city).',
            [
                'type'       => 'object',
                'properties' => [
                    'customer_id' => ['type' => 'string', 'description' => 'Google Ads customer ID'],
                    'campaign_id' => ['type' => 'string', 'description' => 'Optional: filter by campaign ID'],
                    'date_range'  => ['type' => 'string', 'default' => 'LAST_30_DAYS'],
                    'limit'       => ['type' => 'integer', 'default' => 100],
                ],
                'required' => ['customer_id'],
            ],
            [$this, 'getGeoPerformance']
        );

        $server->registerTool(
            'google_ads_get_account_metrics',
            'Get top-level account performance metrics: spend, clicks, impressions, conversions, ROAS.',
            [
                'type'       => 'object',
                'properties' => [
                    'customer_id' => ['type' => 'string', 'description' => 'Google Ads customer ID'],
                    'date_range'  => ['type' => 'string', 'default' => 'LAST_30_DAYS'],
                ],
                'required' => ['customer_id'],
            ],
            [$this, 'getAccountMetrics']
        );

        $server->registerTool(
            'google_ads_query',
            'Execute an arbitrary GAQL (Google Ads Query Language) query. Use for advanced data retrieval.',
            [
                'type'       => 'object',
                'properties' => [
                    'customer_id' => ['type' => 'string', 'description' => 'Google Ads customer ID'],
                    'query'       => ['type' => 'string', 'description' => 'GAQL query string, e.g. "SELECT campaign.id, campaign.name FROM campaign WHERE campaign.status = \'ENABLED\'"'],
                ],
                'required' => ['customer_id', 'query'],
            ],
            [$this, 'runQuery']
        );
    }

    public function listCustomers(array $args): array
    {
        $service = $this->client()->getCustomerServiceClient();
        $response = $service->listAccessibleCustomers(new ListAccessibleCustomersRequest());
        $result = [];
        foreach ($response->getResourceNames() as $name) {
            $result[] = ['resource_name' => $name, 'customer_id' => str_replace('customers/', '', $name)];
        }
        return ['customers' => $result, 'total' => count($result)];
    }

    public function getCampaigns(array $args): array
    {
        $customerId = $args['customer_id'];
        $dateRange  = $args['date_range'] ?? 'LAST_30_DAYS';
        $status     = $args['status'] ?? 'ALL';

        $statusFilter = $status !== 'ALL'
            ? "AND campaign.status = '$status'"
            : "AND campaign.status IN ('ENABLED', 'PAUSED')";

        $query = "
            SELECT
                campaign.id,
                campaign.name,
                campaign.status,
                campaign.advertising_channel_type,
                campaign.bidding_strategy_type,
                campaign_budget.amount_micros,
                metrics.impressions,
                metrics.clicks,
                metrics.cost_micros,
                metrics.conversions,
                metrics.conversions_value,
                metrics.ctr,
                metrics.average_cpc,
                metrics.average_cpm
            FROM campaign
            WHERE segments.date DURING $dateRange
            $statusFilter
            ORDER BY metrics.cost_micros DESC
        ";

        $rows = $this->gaql($customerId, $query);
        return ['campaigns' => $rows, 'total' => count($rows), 'date_range' => $dateRange];
    }

    public function getAdGroups(array $args): array
    {
        $customerId = $args['customer_id'];
        $dateRange  = $args['date_range'] ?? 'LAST_30_DAYS';
        $campaignFilter = isset($args['campaign_id'])
            ? "AND campaign.id = {$args['campaign_id']}"
            : '';

        $query = "
            SELECT
                ad_group.id,
                ad_group.name,
                ad_group.status,
                ad_group.type,
                ad_group.cpc_bid_micros,
                campaign.id,
                campaign.name,
                metrics.impressions,
                metrics.clicks,
                metrics.cost_micros,
                metrics.conversions,
                metrics.ctr,
                metrics.average_cpc
            FROM ad_group
            WHERE segments.date DURING $dateRange
            $campaignFilter
            ORDER BY metrics.cost_micros DESC
        ";

        $rows = $this->gaql($customerId, $query);
        return ['ad_groups' => $rows, 'total' => count($rows)];
    }

    public function getKeywords(array $args): array
    {
        $customerId   = $args['customer_id'];
        $dateRange    = $args['date_range'] ?? 'LAST_30_DAYS';
        $limit        = $args['limit'] ?? 200;
        $filters      = ["ad_group_criterion.type = 'KEYWORD'"];
        if (!empty($args['campaign_id'])) {
            $filters[] = "campaign.id = {$args['campaign_id']}";
        }
        if (!empty($args['ad_group_id'])) {
            $filters[] = "ad_group.id = {$args['ad_group_id']}";
        }
        $where = implode(' AND ', $filters);

        $query = "
            SELECT
                ad_group_criterion.keyword.text,
                ad_group_criterion.keyword.match_type,
                ad_group_criterion.status,
                ad_group_criterion.quality_info.quality_score,
                ad_group_criterion.quality_info.creative_quality_score,
                ad_group_criterion.quality_info.post_click_quality_score,
                ad_group_criterion.quality_info.search_predicted_ctr,
                ad_group_criterion.effective_cpc_bid_micros,
                campaign.id,
                campaign.name,
                ad_group.id,
                ad_group.name,
                metrics.impressions,
                metrics.clicks,
                metrics.cost_micros,
                metrics.conversions,
                metrics.ctr,
                metrics.average_cpc,
                metrics.search_impression_share,
                metrics.search_top_impression_share
            FROM keyword_view
            WHERE segments.date DURING $dateRange
            AND $where
            ORDER BY metrics.cost_micros DESC
            LIMIT $limit
        ";

        $rows = $this->gaql($customerId, $query);
        return ['keywords' => $rows, 'total' => count($rows)];
    }

    public function getSearchTerms(array $args): array
    {
        $customerId = $args['customer_id'];
        $dateRange  = $args['date_range'] ?? 'LAST_30_DAYS';
        $limit      = $args['limit'] ?? 200;
        $campaignFilter = !empty($args['campaign_id'])
            ? "AND campaign.id = {$args['campaign_id']}"
            : '';

        $query = "
            SELECT
                search_term_view.search_term,
                search_term_view.status,
                campaign.id,
                campaign.name,
                ad_group.id,
                ad_group.name,
                metrics.impressions,
                metrics.clicks,
                metrics.cost_micros,
                metrics.conversions,
                metrics.ctr,
                metrics.average_cpc
            FROM search_term_view
            WHERE segments.date DURING $dateRange
            $campaignFilter
            ORDER BY metrics.impressions DESC
            LIMIT $limit
        ";

        $rows = $this->gaql($customerId, $query);
        return ['search_terms' => $rows, 'total' => count($rows)];
    }

    public function getAds(array $args): array
    {
        $customerId = $args['customer_id'];
        $dateRange  = $args['date_range'] ?? 'LAST_30_DAYS';
        $limit      = $args['limit'] ?? 100;
        $filters    = [];
        if (!empty($args['campaign_id'])) {
            $filters[] = "campaign.id = {$args['campaign_id']}";
        }
        if (!empty($args['ad_group_id'])) {
            $filters[] = "ad_group.id = {$args['ad_group_id']}";
        }
        $where = $filters ? ('AND ' . implode(' AND ', $filters)) : '';

        $query = "
            SELECT
                ad_group_ad.ad.id,
                ad_group_ad.ad.name,
                ad_group_ad.ad.type,
                ad_group_ad.ad.final_urls,
                ad_group_ad.ad.responsive_search_ad.headlines,
                ad_group_ad.ad.responsive_search_ad.descriptions,
                ad_group_ad.ad.expanded_text_ad.headline_part1,
                ad_group_ad.ad.expanded_text_ad.headline_part2,
                ad_group_ad.ad.expanded_text_ad.description,
                ad_group_ad.status,
                ad_group_ad.policy_summary.approval_status,
                campaign.id,
                campaign.name,
                ad_group.id,
                ad_group.name,
                metrics.impressions,
                metrics.clicks,
                metrics.cost_micros,
                metrics.conversions,
                metrics.ctr,
                metrics.average_cpc
            FROM ad_group_ad
            WHERE segments.date DURING $dateRange
            $where
            ORDER BY metrics.impressions DESC
            LIMIT $limit
        ";

        $rows = $this->gaql($customerId, $query);
        return ['ads' => $rows, 'total' => count($rows)];
    }

    public function getGeoPerformance(array $args): array
    {
        $customerId = $args['customer_id'];
        $dateRange  = $args['date_range'] ?? 'LAST_30_DAYS';
        $limit      = $args['limit'] ?? 100;
        $campaignFilter = !empty($args['campaign_id'])
            ? "AND campaign.id = {$args['campaign_id']}"
            : '';

        $query = "
            SELECT
                geographic_view.country_criterion_id,
                geographic_view.location_type,
                campaign.id,
                campaign.name,
                metrics.impressions,
                metrics.clicks,
                metrics.cost_micros,
                metrics.conversions,
                metrics.ctr,
                metrics.average_cpc
            FROM geographic_view
            WHERE segments.date DURING $dateRange
            $campaignFilter
            ORDER BY metrics.cost_micros DESC
            LIMIT $limit
        ";

        $rows = $this->gaql($customerId, $query);
        return ['geo_performance' => $rows, 'total' => count($rows)];
    }

    public function getAccountMetrics(array $args): array
    {
        $customerId = $args['customer_id'];
        $dateRange  = $args['date_range'] ?? 'LAST_30_DAYS';

        $query = "
            SELECT
                customer.id,
                customer.descriptive_name,
                customer.currency_code,
                customer.time_zone,
                metrics.impressions,
                metrics.clicks,
                metrics.cost_micros,
                metrics.conversions,
                metrics.conversions_value,
                metrics.ctr,
                metrics.average_cpc,
                metrics.average_cpm,
                metrics.search_impression_share,
                metrics.search_budget_lost_impression_share,
                metrics.search_rank_lost_impression_share,
                metrics.all_conversions,
                metrics.all_conversions_value
            FROM customer
            WHERE segments.date DURING $dateRange
        ";

        $rows = $this->gaql($customerId, $query);
        return ['account_metrics' => $rows, 'date_range' => $dateRange];
    }

    public function runQuery(array $args): array
    {
        $rows = $this->gaql($args['customer_id'], $args['query']);
        return ['results' => $rows, 'total' => count($rows)];
    }
}
