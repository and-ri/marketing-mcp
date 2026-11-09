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

        // REST transport: no ext-grpc needed (searchStream is supported over REST too)
        $builder = (new GoogleAdsClientBuilder())
            ->withTransport('rest')
            ->withDeveloperToken($this->config['GOOGLE_ADS_DEVELOPER_TOKEN'])
            ->withOAuth2Credential($oAuth2Credential);

        if (!empty($this->config['GOOGLE_ADS_LOGIN_CUSTOMER_ID'])) {
            $builder = $builder->withLoginCustomerId((int) $this->config['GOOGLE_ADS_LOGIN_CUSTOMER_ID']);
        }

        $this->client = $builder->build();
        return $this->client;
    }

    private function numericId(mixed $value): int
    {
        $id = (int) preg_replace('/[^0-9]/', '', (string) $value);
        if ($id <= 0) {
            throw new \InvalidArgumentException("Invalid numeric ID: $value");
        }
        return $id;
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
                'properties' => new stdClass(),
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

        $server->registerTool(
            'google_ads_get_asset_performance',
            'Get performance of individual ad assets (headlines, descriptions, images, sitelinks) in RSA and Performance Max campaigns. Includes asset performance labels: BEST, GOOD, LOW, UNRATED. Essential for creative optimization.',
            [
                'type'       => 'object',
                'properties' => [
                    'customer_id' => ['type' => 'string', 'description' => 'Google Ads customer ID'],
                    'campaign_id' => ['type' => 'string', 'description' => 'Optional: filter by campaign ID'],
                    'asset_type'  => ['type' => 'string', 'description' => 'Filter by asset type: TEXT, IMAGE, YOUTUBE_VIDEO, or ALL', 'default' => 'ALL'],
                    'date_range'  => ['type' => 'string', 'default' => 'LAST_30_DAYS'],
                    'limit'       => ['type' => 'integer', 'default' => 200],
                ],
                'required' => ['customer_id'],
            ],
            [$this, 'getAssetPerformance']
        );

        $server->registerTool(
            'google_ads_get_segments',
            'Get campaign performance segmented by device, day of week, or hour of day. Use to identify when and where ads perform best — critical for bid adjustments.',
            [
                'type'       => 'object',
                'properties' => [
                    'customer_id' => ['type' => 'string', 'description' => 'Google Ads customer ID'],
                    'campaign_id' => ['type' => 'string', 'description' => 'Optional: filter by campaign ID'],
                    'segment'     => ['type' => 'string', 'description' => 'Segment dimension: device, day_of_week, hour_of_day', 'default' => 'device'],
                    'date_range'  => ['type' => 'string', 'default' => 'LAST_30_DAYS'],
                ],
                'required' => ['customer_id'],
            ],
            [$this, 'getSegments']
        );

        $server->registerTool(
            'google_ads_get_conversions',
            'Get conversion actions with configuration details and performance stats. Shows conversion types, attribution models, counting methods, and per-conversion-action metrics.',
            [
                'type'       => 'object',
                'properties' => [
                    'customer_id' => ['type' => 'string', 'description' => 'Google Ads customer ID'],
                    'date_range'  => ['type' => 'string', 'default' => 'LAST_30_DAYS'],
                    'limit'       => ['type' => 'integer', 'default' => 100],
                ],
                'required' => ['customer_id'],
            ],
            [$this, 'getConversions']
        );

        $server->registerTool(
            'google_ads_get_change_history',
            'Get the account change history (change_event) — who changed what and when across campaigns, ad groups, bids, budgets, ads, and criteria. Google only retains the last 30 days of change history.',
            [
                'type'       => 'object',
                'properties' => [
                    'customer_id'   => ['type' => 'string', 'description' => 'Google Ads customer ID'],
                    'days'          => ['type' => 'integer', 'description' => 'How many past days to fetch (max 30, capped automatically)', 'default' => 14],
                    'resource_type' => ['type' => 'string', 'description' => 'Optional filter, e.g. CAMPAIGN, AD_GROUP, AD_GROUP_AD, AD_GROUP_CRITERION, CAMPAIGN_CRITERION, CAMPAIGN_BUDGET, BIDDING_STRATEGY'],
                    'limit'         => ['type' => 'integer', 'description' => 'Max rows (Google hard limit 10000)', 'default' => 500],
                ],
                'required' => ['customer_id'],
            ],
            [$this, 'getChangeHistory']
        );

        $server->registerTool(
            'google_ads_get_negative_keyword_lists',
            'Get shared negative keyword lists (shared sets of type NEGATIVE_KEYWORDS): the lists, their keyword members, and which campaigns each list is attached to.',
            [
                'type'       => 'object',
                'properties' => [
                    'customer_id' => ['type' => 'string', 'description' => 'Google Ads customer ID'],
                ],
                'required' => ['customer_id'],
            ],
            [$this, 'getNegativeKeywordLists']
        );

        $server->registerTool(
            'google_ads_get_negative_keywords',
            'Get negative keywords applied directly at the campaign level and the ad group level (excludes shared lists — use google_ads_get_negative_keyword_lists for those).',
            [
                'type'       => 'object',
                'properties' => [
                    'customer_id' => ['type' => 'string', 'description' => 'Google Ads customer ID'],
                    'campaign_id' => ['type' => 'string', 'description' => 'Optional: filter by campaign ID'],
                    'limit'       => ['type' => 'integer', 'default' => 500],
                ],
                'required' => ['customer_id'],
            ],
            [$this, 'getNegativeKeywords']
        );

        $server->registerTool(
            'google_ads_get_audiences',
            'Get audience segments / user lists, including custom-uploaded Customer Match lists (user_list.type = CRM_BASED) with match rates, plus remarketing, similar, rule-based lists and combined audiences. Shows sizes, membership status and eligibility.',
            [
                'type'       => 'object',
                'properties' => [
                    'customer_id' => ['type' => 'string', 'description' => 'Google Ads customer ID'],
                    'list_type'   => ['type' => 'string', 'description' => 'Optional user_list.type filter, e.g. CRM_BASED (uploaded Customer Match), REMARKETING, SIMILAR, RULE_BASED, LOGICAL'],
                ],
                'required' => ['customer_id'],
            ],
            [$this, 'getAudiences']
        );

        $server->registerTool(
            'google_ads_get_audience_performance',
            'Get performance of audience segments attached to campaigns and ad groups (campaign_audience_view, ad_group_audience_view) with impressions, clicks, cost, and conversions per audience.',
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
            [$this, 'getAudiencePerformance']
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
            ? 'AND campaign.id = ' . $this->numericId($args['campaign_id'])
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
            $filters[] = 'campaign.id = ' . $this->numericId($args['campaign_id']);
        }
        if (!empty($args['ad_group_id'])) {
            $filters[] = 'ad_group.id = ' . $this->numericId($args['ad_group_id']);
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
            ? 'AND campaign.id = ' . $this->numericId($args['campaign_id'])
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
            $filters[] = 'campaign.id = ' . $this->numericId($args['campaign_id']);
        }
        if (!empty($args['ad_group_id'])) {
            $filters[] = 'ad_group.id = ' . $this->numericId($args['ad_group_id']);
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
            ? 'AND campaign.id = ' . $this->numericId($args['campaign_id'])
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

    public function getAssetPerformance(array $args): array
    {
        $customerId    = $args['customer_id'];
        $dateRange     = $args['date_range'] ?? 'LAST_30_DAYS';
        $limit         = $args['limit'] ?? 200;
        $assetType     = $args['asset_type'] ?? 'ALL';
        $campaignFilter = !empty($args['campaign_id'])
            ? 'AND campaign.id = ' . $this->numericId($args['campaign_id'])
            : '';
        $typeFilter = $assetType !== 'ALL'
            ? "AND asset.type = '$assetType'"
            : '';

        $query = "
            SELECT
                asset.id,
                asset.name,
                asset.type,
                asset.text_asset.text,
                asset.image_asset.full_size.url,
                asset.image_asset.full_size.width_pixels,
                asset.image_asset.full_size.height_pixels,
                asset.youtube_video_asset.youtube_video_id,
                ad_group_ad_asset_view.field_type,
                ad_group_ad_asset_view.performance_label,
                ad_group_ad_asset_view.enabled,
                ad_group_ad_asset_view.pinned_field,
                campaign.id,
                campaign.name,
                ad_group.id,
                ad_group.name,
                metrics.impressions,
                metrics.clicks,
                metrics.cost_micros,
                metrics.conversions,
                metrics.ctr
            FROM ad_group_ad_asset_view
            WHERE segments.date DURING $dateRange
            $campaignFilter
            $typeFilter
            ORDER BY metrics.impressions DESC
            LIMIT $limit
        ";

        $rows = $this->gaql($customerId, $query);
        return ['assets' => $rows, 'total' => count($rows), 'date_range' => $dateRange];
    }

    public function getSegments(array $args): array
    {
        $customerId    = $args['customer_id'];
        $dateRange     = $args['date_range'] ?? 'LAST_30_DAYS';
        $segment       = $args['segment'] ?? 'device';
        $campaignFilter = !empty($args['campaign_id'])
            ? 'AND campaign.id = ' . $this->numericId($args['campaign_id'])
            : '';

        $segmentField = match ($segment) {
            'day_of_week'  => 'segments.day_of_week',
            'hour_of_day'  => 'segments.hour_of_day',
            default        => 'segments.device',
        };

        $query = "
            SELECT
                campaign.id,
                campaign.name,
                $segmentField,
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
            AND campaign.status IN ('ENABLED', 'PAUSED')
            $campaignFilter
            ORDER BY metrics.cost_micros DESC
        ";

        $rows = $this->gaql($customerId, $query);
        return ['segments' => $rows, 'total' => count($rows), 'segment' => $segment, 'date_range' => $dateRange];
    }

    public function getConversions(array $args): array
    {
        $customerId = $args['customer_id'];
        $dateRange  = $args['date_range'] ?? 'LAST_30_DAYS';
        $limit      = $args['limit'] ?? 100;

        $query = "
            SELECT
                conversion_action.id,
                conversion_action.name,
                conversion_action.status,
                conversion_action.type,
                conversion_action.category,
                conversion_action.counting_type,
                conversion_action.attribution_model_settings.attribution_model,
                conversion_action.value_settings.default_value,
                conversion_action.value_settings.always_use_default_value,
                conversion_action.click_through_lookback_window_days,
                conversion_action.view_through_lookback_window_days,
                conversion_action.include_in_conversions_metric,
                metrics.conversions,
                metrics.conversions_value,
                metrics.all_conversions,
                metrics.all_conversions_value,
                metrics.cost_per_conversion,
                metrics.conversion_rate
            FROM conversion_action
            WHERE segments.date DURING $dateRange
            AND conversion_action.status != 'REMOVED'
            ORDER BY metrics.conversions DESC
            LIMIT $limit
        ";

        $rows = $this->gaql($customerId, $query);
        return ['conversions' => $rows, 'total' => count($rows), 'date_range' => $dateRange];
    }

    public function getChangeHistory(array $args): array
    {
        $customerId = $args['customer_id'];
        $days       = min(max((int) ($args['days'] ?? 14), 1), 30);
        $limit      = min((int) ($args['limit'] ?? 500), 10000);
        $start      = date('Y-m-d H:i:s', strtotime("-$days days"));
        $end        = date('Y-m-d H:i:s');

        $typeFilter = '';
        if (!empty($args['resource_type'])) {
            $type = preg_replace('/[^A-Z_]/', '', strtoupper((string) $args['resource_type']));
            if ($type !== '') {
                $typeFilter = "AND change_event.change_resource_type = '$type'";
            }
        }

        $query = "
            SELECT
                change_event.change_date_time,
                change_event.user_email,
                change_event.client_type,
                change_event.change_resource_type,
                change_event.resource_change_operation,
                change_event.changed_fields,
                change_event.old_resource,
                change_event.new_resource,
                change_event.campaign,
                change_event.ad_group
            FROM change_event
            WHERE change_event.change_date_time >= '$start'
            AND change_event.change_date_time <= '$end'
            $typeFilter
            ORDER BY change_event.change_date_time DESC
            LIMIT $limit
        ";

        $rows = $this->gaql($customerId, $query);
        return ['changes' => $rows, 'total' => count($rows), 'days' => $days];
    }

    public function getNegativeKeywordLists(array $args): array
    {
        $customerId = $args['customer_id'];

        $lists = $this->gaql($customerId, "
            SELECT
                shared_set.id,
                shared_set.name,
                shared_set.type,
                shared_set.status,
                shared_set.member_count,
                shared_set.reference_count
            FROM shared_set
            WHERE shared_set.type = 'NEGATIVE_KEYWORDS'
            AND shared_set.status != 'REMOVED'
        ");

        $members = $this->gaql($customerId, "
            SELECT
                shared_set.id,
                shared_set.name,
                shared_criterion.keyword.text,
                shared_criterion.keyword.match_type
            FROM shared_criterion
            WHERE shared_set.type = 'NEGATIVE_KEYWORDS'
            AND shared_criterion.type = 'KEYWORD'
        ");

        $attachments = $this->gaql($customerId, "
            SELECT
                campaign.id,
                campaign.name,
                shared_set.id,
                shared_set.name
            FROM campaign_shared_set
            WHERE shared_set.type = 'NEGATIVE_KEYWORDS'
            AND campaign_shared_set.status != 'REMOVED'
        ");

        return [
            'lists'       => $lists,
            'members'     => $members,
            'attachments' => $attachments,
            'total_lists' => count($lists),
        ];
    }

    public function getNegativeKeywords(array $args): array
    {
        $customerId = $args['customer_id'];
        $limit      = $args['limit'] ?? 500;
        $campaignFilter = !empty($args['campaign_id'])
            ? 'AND campaign.id = ' . $this->numericId($args['campaign_id'])
            : '';

        $campaignLevel = $this->gaql($customerId, "
            SELECT
                campaign.id,
                campaign.name,
                campaign_criterion.keyword.text,
                campaign_criterion.keyword.match_type
            FROM campaign_criterion
            WHERE campaign_criterion.negative = TRUE
            AND campaign_criterion.type = 'KEYWORD'
            $campaignFilter
            LIMIT $limit
        ");

        $adGroupLevel = $this->gaql($customerId, "
            SELECT
                campaign.id,
                campaign.name,
                ad_group.id,
                ad_group.name,
                ad_group_criterion.keyword.text,
                ad_group_criterion.keyword.match_type
            FROM ad_group_criterion
            WHERE ad_group_criterion.negative = TRUE
            AND ad_group_criterion.type = 'KEYWORD'
            $campaignFilter
            LIMIT $limit
        ");

        return [
            'campaign_level' => $campaignLevel,
            'ad_group_level' => $adGroupLevel,
            'total'          => count($campaignLevel) + count($adGroupLevel),
        ];
    }

    public function getAudiences(array $args): array
    {
        $customerId = $args['customer_id'];
        $typeFilter = '';
        if (!empty($args['list_type'])) {
            $type = preg_replace('/[^A-Z_]/', '', strtoupper((string) $args['list_type']));
            if ($type !== '') {
                $typeFilter = "AND user_list.type = '$type'";
            }
        }

        $userLists = $this->gaql($customerId, "
            SELECT
                user_list.id,
                user_list.name,
                user_list.description,
                user_list.type,
                user_list.membership_status,
                user_list.membership_life_span,
                user_list.size_for_display,
                user_list.size_for_search,
                user_list.eligible_for_display,
                user_list.eligible_for_search,
                user_list.match_rate_percentage,
                user_list.closing_reason
            FROM user_list
            WHERE user_list.type != 'UNKNOWN'
            $typeFilter
            ORDER BY user_list.size_for_search DESC
        ");

        $audiences = empty($args['list_type']) ? $this->gaql($customerId, "
            SELECT
                audience.id,
                audience.name,
                audience.description,
                audience.status
            FROM audience
            WHERE audience.status != 'REMOVED'
        ") : [];

        return [
            'user_lists'        => $userLists,
            'audiences'         => $audiences,
            'total_user_lists'  => count($userLists),
        ];
    }

    public function getAudiencePerformance(array $args): array
    {
        $customerId = $args['customer_id'];
        $dateRange  = $args['date_range'] ?? 'LAST_30_DAYS';
        $limit      = $args['limit'] ?? 200;
        $campaignFilter = !empty($args['campaign_id'])
            ? 'AND campaign.id = ' . $this->numericId($args['campaign_id'])
            : '';

        $adGroupLevel = $this->gaql($customerId, "
            SELECT
                campaign.id,
                campaign.name,
                ad_group.id,
                ad_group.name,
                ad_group_criterion.criterion_id,
                ad_group_criterion.display_name,
                ad_group_criterion.type,
                metrics.impressions,
                metrics.clicks,
                metrics.cost_micros,
                metrics.conversions,
                metrics.conversions_value,
                metrics.ctr,
                metrics.average_cpc
            FROM ad_group_audience_view
            WHERE segments.date DURING $dateRange
            $campaignFilter
            ORDER BY metrics.cost_micros DESC
            LIMIT $limit
        ");

        $campaignLevel = $this->gaql($customerId, "
            SELECT
                campaign.id,
                campaign.name,
                campaign_criterion.criterion_id,
                campaign_criterion.display_name,
                campaign_criterion.type,
                metrics.impressions,
                metrics.clicks,
                metrics.cost_micros,
                metrics.conversions,
                metrics.conversions_value,
                metrics.ctr,
                metrics.average_cpc
            FROM campaign_audience_view
            WHERE segments.date DURING $dateRange
            $campaignFilter
            ORDER BY metrics.cost_micros DESC
            LIMIT $limit
        ");

        return [
            'ad_group_level' => $adGroupLevel,
            'campaign_level' => $campaignLevel,
            'total'          => count($adGroupLevel) + count($campaignLevel),
            'date_range'     => $dateRange,
        ];
    }
}
