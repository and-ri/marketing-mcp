<?php

use FacebookAds\Api;
use FacebookAds\Object\AdAccount;
use FacebookAds\Object\Campaign;
use FacebookAds\Object\AdSet;
use FacebookAds\Object\Ad;

class MetaAdsProvider
{
    private array $config;
    private bool $initialized = false;

    public function __construct(array $config = [])
    {
        $this->config = $config ?: $_ENV;
    }

    private function init(): void
    {
        if ($this->initialized) {
            return;
        }
        Api::init(
            $this->config['META_APP_ID'],
            $this->config['META_APP_SECRET'],
            $this->config['META_ACCESS_TOKEN']
        );
        $this->initialized = true;
    }

    private function account(string $accountId): AdAccount
    {
        $this->init();
        $id = str_starts_with($accountId, 'act_') ? $accountId : "act_$accountId";
        return new AdAccount($id);
    }

    private function cursorToArray(mixed $cursor): array
    {
        $result = [];
        foreach ($cursor as $item) {
            $result[] = $item->exportAllData();
        }
        return $result;
    }

    public function registerTools(McpServer $server): void
    {
        $server->registerTool(
            'meta_get_ad_accounts',
            'List all accessible Meta (Facebook/Instagram) ad accounts.',
            [
                'type'       => 'object',
                'properties' => new stdClass(),
                'required'   => [],
            ],
            [$this, 'getAdAccounts']
        );

        $server->registerTool(
            'meta_get_campaigns',
            'Get campaigns for a Meta ad account with spend and performance metrics.',
            [
                'type'       => 'object',
                'properties' => [
                    'account_id'  => ['type' => 'string', 'description' => 'Meta ad account ID (with or without "act_" prefix)'],
                    'date_preset' => ['type' => 'string', 'description' => 'Date preset: last_7d, last_30d, last_90d, this_month, last_month, lifetime', 'default' => 'last_30d'],
                    'status'      => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Filter by status: ACTIVE, PAUSED, ARCHIVED, DELETED', 'default' => ['ACTIVE', 'PAUSED']],
                    'limit'       => ['type' => 'integer', 'default' => 50],
                ],
                'required' => ['account_id'],
            ],
            [$this, 'getCampaigns']
        );

        $server->registerTool(
            'meta_get_adsets',
            'Get ad sets for an account or specific campaign with targeting and performance data.',
            [
                'type'       => 'object',
                'properties' => [
                    'account_id'   => ['type' => 'string', 'description' => 'Meta ad account ID'],
                    'campaign_id'  => ['type' => 'string', 'description' => 'Optional: filter by campaign ID'],
                    'date_preset'  => ['type' => 'string', 'default' => 'last_30d'],
                    'limit'        => ['type' => 'integer', 'default' => 50],
                ],
                'required' => ['account_id'],
            ],
            [$this, 'getAdSets']
        );

        $server->registerTool(
            'meta_get_ads',
            'Get individual ads with creative details and performance metrics.',
            [
                'type'       => 'object',
                'properties' => [
                    'account_id'   => ['type' => 'string', 'description' => 'Meta ad account ID'],
                    'campaign_id'  => ['type' => 'string', 'description' => 'Optional: filter by campaign ID'],
                    'adset_id'     => ['type' => 'string', 'description' => 'Optional: filter by ad set ID'],
                    'date_preset'  => ['type' => 'string', 'default' => 'last_30d'],
                    'limit'        => ['type' => 'integer', 'default' => 50],
                ],
                'required' => ['account_id'],
            ],
            [$this, 'getAds']
        );

        $server->registerTool(
            'meta_get_insights',
            'Get detailed performance insights with breakdowns by age, gender, placement, device, or region.',
            [
                'type'       => 'object',
                'properties' => [
                    'account_id'  => ['type' => 'string', 'description' => 'Meta ad account ID'],
                    'object_id'   => ['type' => 'string', 'description' => 'Optional: campaign, adset, or ad ID to scope insights'],
                    'object_type' => ['type' => 'string', 'description' => 'Type of object_id: account, campaign, adset, ad', 'default' => 'account'],
                    'date_preset' => ['type' => 'string', 'default' => 'last_30d'],
                    'breakdowns'  => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Breakdown dimensions: age, gender, placement, impression_device, region, country'],
                    'limit'       => ['type' => 'integer', 'default' => 100],
                ],
                'required' => ['account_id'],
            ],
            [$this, 'getInsights']
        );

        $server->registerTool(
            'meta_get_custom_audiences',
            'List custom and lookalike audiences for a Meta ad account.',
            [
                'type'       => 'object',
                'properties' => [
                    'account_id' => ['type' => 'string', 'description' => 'Meta ad account ID'],
                    'limit'      => ['type' => 'integer', 'default' => 50],
                ],
                'required' => ['account_id'],
            ],
            [$this, 'getCustomAudiences']
        );

        $server->registerTool(
            'meta_get_pixels',
            'List Meta Pixel IDs and their configurations for a Meta ad account.',
            [
                'type'       => 'object',
                'properties' => [
                    'account_id' => ['type' => 'string', 'description' => 'Meta ad account ID'],
                ],
                'required' => ['account_id'],
            ],
            [$this, 'getPixels']
        );

        $server->registerTool(
            'meta_get_creatives',
            'Get ad creatives (images, videos, copy) for a Meta ad account.',
            [
                'type'       => 'object',
                'properties' => [
                    'account_id' => ['type' => 'string', 'description' => 'Meta ad account ID'],
                    'limit'      => ['type' => 'integer', 'default' => 50],
                ],
                'required' => ['account_id'],
            ],
            [$this, 'getCreatives']
        );
    }

    public function getAdAccounts(array $args): array
    {
        $this->init();
        $fields   = 'id,name,account_status,currency,timezone_name,amount_spent,balance,spend_cap';
        $response = Api::instance()->call('/me/adaccounts', 'GET', ['fields' => $fields, 'limit' => 100]);
        $data     = $response->getContent()['data'] ?? [];
        return ['accounts' => $data, 'total' => count($data)];
    }

    public function getCampaigns(array $args): array
    {
        $account    = $this->account($args['account_id']);
        $datePreset = $args['date_preset'] ?? 'last_30d';
        $status     = $args['status'] ?? ['ACTIVE', 'PAUSED'];
        $limit      = $args['limit'] ?? 50;

        $fields = [
            'id', 'name', 'status', 'objective', 'effective_status',
            'start_time', 'stop_time', 'daily_budget', 'lifetime_budget',
            'budget_remaining', 'bid_strategy', 'special_ad_categories',
        ];

        $campaigns = $account->getCampaigns($fields, [
            'effective_status' => $status,
            'limit'            => $limit,
        ]);

        $rows = $this->cursorToArray($campaigns);

        // Attach top-level insights
        foreach ($rows as &$row) {
            try {
                $campaign = new Campaign($row['id']);
                $insights = $campaign->getInsights(
                    ['impressions', 'clicks', 'spend', 'ctr', 'cpc', 'cpm', 'reach', 'frequency', 'actions', 'action_values', 'roas'],
                    ['date_preset' => $datePreset, 'limit' => 1]
                );
                $row['insights'] = count($insights) > 0 ? $insights[0]->exportAllData() : [];
            } catch (Throwable $e) {
                $row['insights_error'] = $e->getMessage();
            }
        }
        unset($row);

        return ['campaigns' => $rows, 'total' => count($rows), 'date_preset' => $datePreset];
    }

    public function getAdSets(array $args): array
    {
        $account    = $this->account($args['account_id']);
        $datePreset = $args['date_preset'] ?? 'last_30d';
        $limit      = $args['limit'] ?? 50;

        $fields = [
            'id', 'name', 'status', 'effective_status', 'campaign_id',
            'daily_budget', 'lifetime_budget', 'bid_amount', 'bid_strategy',
            'optimization_goal', 'billing_event',
            'targeting', 'start_time', 'end_time',
        ];

        $params = ['limit' => $limit];
        if (!empty($args['campaign_id'])) {
            $params['campaign_id'] = $args['campaign_id'];
        }

        $adsets = $account->getAdSets($fields, $params);
        $rows   = $this->cursorToArray($adsets);

        foreach ($rows as &$row) {
            try {
                $adset    = new AdSet($row['id']);
                $insights = $adset->getInsights(
                    ['impressions', 'clicks', 'spend', 'ctr', 'cpc', 'cpm', 'reach', 'actions'],
                    ['date_preset' => $datePreset, 'limit' => 1]
                );
                $row['insights'] = count($insights) > 0 ? $insights[0]->exportAllData() : [];
            } catch (Throwable $e) {
                $row['insights_error'] = $e->getMessage();
            }
        }
        unset($row);

        return ['adsets' => $rows, 'total' => count($rows)];
    }

    public function getAds(array $args): array
    {
        $account    = $this->account($args['account_id']);
        $datePreset = $args['date_preset'] ?? 'last_30d';
        $limit      = $args['limit'] ?? 50;

        $fields = [
            'id', 'name', 'status', 'effective_status', 'campaign_id', 'adset_id',
            'creative', 'adlabels', 'tracking_specs', 'bid_amount',
        ];

        $params = ['limit' => $limit];
        if (!empty($args['campaign_id'])) {
            $params['campaign_id'] = $args['campaign_id'];
        }
        if (!empty($args['adset_id'])) {
            $params['adset_id'] = $args['adset_id'];
        }

        $ads  = $account->getAds($fields, $params);
        $rows = $this->cursorToArray($ads);

        foreach ($rows as &$row) {
            try {
                $ad       = new Ad($row['id']);
                $insights = $ad->getInsights(
                    ['impressions', 'clicks', 'spend', 'ctr', 'cpc', 'cpm', 'reach', 'frequency', 'actions', 'action_values'],
                    ['date_preset' => $datePreset, 'limit' => 1]
                );
                $row['insights'] = count($insights) > 0 ? $insights[0]->exportAllData() : [];
            } catch (Throwable $e) {
                $row['insights_error'] = $e->getMessage();
            }
        }
        unset($row);

        return ['ads' => $rows, 'total' => count($rows)];
    }

    public function getInsights(array $args): array
    {
        $this->init();
        $datePreset = $args['date_preset'] ?? 'last_30d';
        $breakdowns = $args['breakdowns'] ?? [];
        $limit      = $args['limit'] ?? 100;
        $objectType = $args['object_type'] ?? 'account';

        $fields = [
            'campaign_id', 'campaign_name', 'adset_id', 'adset_name', 'ad_id', 'ad_name',
            'impressions', 'clicks', 'spend', 'reach', 'frequency',
            'ctr', 'cpc', 'cpm', 'cpp',
            'actions', 'action_values',
            'cost_per_action_type',
            'date_start', 'date_stop',
        ];

        $params = [
            'date_preset'  => $datePreset,
            'limit'        => $limit,
            'level'        => $objectType === 'account' ? 'campaign' : $objectType,
        ];
        if ($breakdowns) {
            $params['breakdowns'] = $breakdowns;
        }

        if (!empty($args['object_id'])) {
            $objectId = $args['object_id'];
            $object   = match ($objectType) {
                'campaign' => new Campaign($objectId),
                'adset'    => new AdSet($objectId),
                'ad'       => new Ad($objectId),
                default    => $this->account($args['account_id']),
            };
        } else {
            $object = $this->account($args['account_id']);
        }

        $insights = $object->getInsights($fields, $params);
        $rows     = $this->cursorToArray($insights);

        return ['insights' => $rows, 'total' => count($rows), 'date_preset' => $datePreset, 'breakdowns' => $breakdowns];
    }

    public function getCustomAudiences(array $args): array
    {
        $account = $this->account($args['account_id']);
        $fields  = [
            'id', 'name', 'description', 'subtype', 'approximate_count_lower_bound',
            'approximate_count_upper_bound', 'data_source', 'lookalike_spec',
            'retention_days', 'time_created', 'time_updated',
        ];

        $audiences = $account->getCustomAudiences($fields, ['limit' => $args['limit'] ?? 50]);
        $rows      = $this->cursorToArray($audiences);
        return ['audiences' => $rows, 'total' => count($rows)];
    }

    public function getPixels(array $args): array
    {
        $account = $this->account($args['account_id']);
        $fields  = [
            'id', 'name', 'code', 'creation_time', 'last_fired_time',
            'is_unavailable', 'owner_business', 'owner_ad_account',
        ];

        $pixels = $account->getAdsPixels($fields);
        $rows   = $this->cursorToArray($pixels);
        return ['pixels' => $rows, 'total' => count($rows)];
    }

    public function getCreatives(array $args): array
    {
        $account = $this->account($args['account_id']);
        $fields  = [
            'id', 'name', 'title', 'body', 'object_type',
            'image_url', 'thumbnail_url',
            'call_to_action_type',
            'link_url', 'object_url',
            'status',
        ];

        $creatives = $account->getAdCreatives($fields, ['limit' => $args['limit'] ?? 50]);
        $rows      = $this->cursorToArray($creatives);
        return ['creatives' => $rows, 'total' => count($rows)];
    }
}
