# marketing-mcp

MCP server that exposes Google Ads, Google Analytics 4, and Meta Ads data as tools.
Built in PHP. Two transports supported: **stdio** (local) and **HTTP** (remote/multi-user).

---

## Local setup (stdio, single user)

```bash
# 1. Install dependencies
composer install

# 2. Authorize (interactive OAuth2 flow — opens browser)
php auth.php

# 3. Add to Claude / any MCP client
```

MCP client config (`claude_desktop_config.json` or `.claude/settings.json`):

```json
{
  "mcpServers": {
    "marketing": {
      "command": "php",
      "args": ["/path/to/marketing-mcp/app.php"]
    }
  }
}
```

---

## Server setup (HTTP, multi-user, Docker)

Run the server once and connect from any device with a Bearer token.

### 1 — Prerequisites

- A Linux server (VPS/cloud) with Docker + Docker Compose installed
- A domain pointing to your server's IP (e.g. `mcp.example.com`)

### 2 — Clone and get certificates

```bash
git clone https://github.com/you/marketing-mcp.git
cd marketing-mcp

# Replace YOUR_DOMAIN and YOUR_EMAIL throughout
export DOMAIN=mcp.example.com
export EMAIL=you@example.com

# Temporarily start nginx on port 80 to pass the ACME challenge
sed -i "s/YOUR_DOMAIN/$DOMAIN/g" nginx/mcp.conf
docker compose up -d nginx

# Issue the certificate (one-time)
docker compose run --rm certbot certonly \
  --webroot -w /var/www/certbot \
  -d $DOMAIN --email $EMAIL --agree-tos --no-eff-email

# Start everything
docker compose up -d
```

### 3 — Create users

Run `users.php` inside the container to manage users and their API credentials.

```bash
# Create a user — prints a Bearer token
docker compose exec mcp php users.php add alice

# Import credentials from a .env file
docker compose exec -T mcp php users.php env:import alice < alice.env

# Or set individual values
docker compose exec mcp php users.php env:set alice META_ACCESS_TOKEN EAAxxxxx

# List all users
docker compose exec mcp php users.php list

# Show env vars for a user (values are masked)
docker compose exec mcp php users.php env:show alice

# Regenerate a compromised token
docker compose exec mcp php users.php token:reset alice

# Remove a user
docker compose exec mcp php users.php remove alice
```

Credentials stored per user (same keys as `.env.example`):

| Platform | Keys |
|---|---|
| Google Ads | `GOOGLE_ADS_DEVELOPER_TOKEN`, `GOOGLE_ADS_CLIENT_ID`, `GOOGLE_ADS_CLIENT_SECRET`, `GOOGLE_ADS_REFRESH_TOKEN`, `GOOGLE_ADS_LOGIN_CUSTOMER_ID` (MCC only) |
| Google Analytics 4 | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REFRESH_TOKEN` |
| Meta Ads | `META_APP_ID`, `META_APP_SECRET`, `META_ACCESS_TOKEN` |

### 4 — Connect from Claude Code

Add to `~/.claude.json` (global) or `.mcp.json` (project):

```json
{
  "mcpServers": {
    "marketing": {
      "type": "http",
      "url": "https://mcp.example.com/mcp",
      "headers": {
        "Authorization": "Bearer <token printed by users.php add>"
      }
    }
  }
}
```

Each user gets their own token and their own set of API credentials. The server is stateless — every request authenticates via the Bearer token and loads the user's credentials from SQLite before executing the tool.

### 5 — Maintenance

```bash
# View logs
docker compose logs -f mcp

# Restart after code changes
docker compose up -d --build mcp

# Certificate renewal runs automatically via the certbot container.
# Force a manual renewal:
docker compose exec certbot certbot renew
docker compose exec nginx nginx -s reload
```

---

## Credentials (local setup)

`php auth.php` writes to `.env` automatically. You can also fill it manually — copy `.env.example` to `.env`.

Required fields per platform:

| Platform | Keys needed in .env |
|---|---|
| Google Ads | `GOOGLE_ADS_DEVELOPER_TOKEN`, `GOOGLE_ADS_CLIENT_ID`, `GOOGLE_ADS_CLIENT_SECRET`, `GOOGLE_ADS_REFRESH_TOKEN` |
| Google Analytics 4 | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REFRESH_TOKEN` |
| Meta Ads | `META_APP_ID`, `META_APP_SECRET`, `META_ACCESS_TOKEN` |

Google Ads manager accounts also need `GOOGLE_ADS_LOGIN_CUSTOMER_ID`.

---

## Tools reference

### Identifiers to know before calling tools

| Platform | How to find IDs |
|---|---|
| Google Ads `customer_id` | 10-digit number, no dashes. Call `google_ads_list_customers` first. |
| GA4 `property_id` | Numeric ID from Analytics → Admin → Property Settings. Example: `123456789`. |
| Meta `account_id` | Numeric ID, with or without `act_` prefix. Call `meta_get_ad_accounts` first. |

---

## Google Ads tools

All Google Ads tools accept `date_range` with these values:
`TODAY` `YESTERDAY` `LAST_7_DAYS` `LAST_14_DAYS` `LAST_30_DAYS` `THIS_MONTH` `LAST_MONTH` `THIS_QUARTER` `LAST_QUARTER` `THIS_YEAR` `LAST_YEAR`

All monetary values are returned in **micros** (divide by 1,000,000 for the currency amount).

---

### `google_ads_list_customers`

List all Google Ads accounts accessible with the current OAuth2 credentials.
Call this first to get `customer_id` values for other tools.

**Parameters:** none

**Returns:** `{ customers: [{ customer_id, resource_name }], total }`

---

### `google_ads_get_campaigns`

Campaigns with spend, clicks, impressions, CTR, CPC, CPM, conversions, and conversion value.

| Parameter | Type | Required | Default | Notes |
|---|---|---|---|---|
| `customer_id` | string | yes | — | 10-digit, no dashes |
| `date_range` | string | no | `LAST_30_DAYS` | See values above |
| `status` | string | no | `ALL` | `ENABLED` \| `PAUSED` \| `REMOVED` \| `ALL` |

**Returns:** `{ campaigns: [...], total, date_range }`

Each campaign row includes: `campaign.id`, `campaign.name`, `campaign.status`, `campaign.advertising_channel_type`, `campaign.bidding_strategy_type`, `campaign_budget.amount_micros`, and all `metrics.*`.

---

### `google_ads_get_ad_groups`

Ad groups with bids and metrics. Optionally scoped to one campaign.

| Parameter | Type | Required | Default |
|---|---|---|---|
| `customer_id` | string | yes | — |
| `campaign_id` | string | no | — |
| `date_range` | string | no | `LAST_30_DAYS` |

**Returns:** `{ ad_groups: [...], total }`

---

### `google_ads_get_keywords`

Keywords with quality score components, match type, effective CPC bid, and performance metrics.
Also returns `search_impression_share` and `search_top_impression_share`.

| Parameter | Type | Required | Default |
|---|---|---|---|
| `customer_id` | string | yes | — |
| `campaign_id` | string | no | — |
| `ad_group_id` | string | no | — |
| `date_range` | string | no | `LAST_30_DAYS` |
| `limit` | integer | no | `200` |

**Returns:** `{ keywords: [...], total }`

Quality score fields: `quality_score` (1–10), `creative_quality_score`, `post_click_quality_score`, `search_predicted_ctr`.

---

### `google_ads_get_search_terms`

What users actually typed to trigger ads. Essential for negative keyword discovery and match type decisions.

| Parameter | Type | Required | Default |
|---|---|---|---|
| `customer_id` | string | yes | — |
| `campaign_id` | string | no | — |
| `date_range` | string | no | `LAST_30_DAYS` |
| `limit` | integer | no | `200` |

**Returns:** `{ search_terms: [...], total }`

Each row: `search_term_view.search_term`, `search_term_view.status` (`ADDED` / `EXCLUDED` / `NONE`), campaign/ad group context, and metrics.

---

### `google_ads_get_ads`

Individual ads with creative text and approval status.

| Parameter | Type | Required | Default |
|---|---|---|---|
| `customer_id` | string | yes | — |
| `campaign_id` | string | no | — |
| `ad_group_id` | string | no | — |
| `date_range` | string | no | `LAST_30_DAYS` |
| `limit` | integer | no | `100` |

**Returns:** `{ ads: [...], total }`

Creative fields for RSAs: `responsive_search_ad.headlines`, `responsive_search_ad.descriptions`.
For ETAs: `expanded_text_ad.headline_part1/2`, `expanded_text_ad.description`.

---

### `google_ads_get_geo_performance`

Performance by geographic location. Rows identify locations by `country_criterion_id` — use the [geo target constants](https://developers.google.com/google-ads/api/data/geotargets) to resolve names.

| Parameter | Type | Required | Default |
|---|---|---|---|
| `customer_id` | string | yes | — |
| `campaign_id` | string | no | — |
| `date_range` | string | no | `LAST_30_DAYS` |
| `limit` | integer | no | `100` |

**Returns:** `{ geo_performance: [...], total }`

---

### `google_ads_get_account_metrics`

Single-row summary for the whole account: total spend, clicks, impressions, conversions, conversion value, impression share, and lost IS (budget and rank).

| Parameter | Type | Required | Default |
|---|---|---|---|
| `customer_id` | string | yes | — |
| `date_range` | string | no | `LAST_30_DAYS` |

**Returns:** `{ account_metrics: [...], date_range }`

---

### `google_ads_query`

Execute any [GAQL](https://developers.google.com/google-ads/api/docs/query/overview) query directly.
Use this when the other tools don't cover what you need (e.g. shopping, video, asset performance, change history).

| Parameter | Type | Required |
|---|---|---|
| `customer_id` | string | yes |
| `query` | string | yes |

**Returns:** `{ results: [...], total }`

Example queries:
```sql
-- Shopping product groups
SELECT shopping_performance_view.resource_name, metrics.clicks, metrics.cost_micros
FROM shopping_performance_view
WHERE segments.date DURING LAST_30_DAYS

-- Audience performance
SELECT ad_group.id, audience_view.resource_name, metrics.impressions, metrics.cost_micros
FROM audience_view
WHERE segments.date DURING LAST_30_DAYS

-- Change history
SELECT change_event.change_date_time, change_event.change_resource_name,
       change_event.change_resource_type, change_event.user_email
FROM change_event
WHERE change_event.change_date_time DURING LAST_14_DAYS
ORDER BY change_event.change_date_time DESC
LIMIT 50
```

---

## Google Analytics 4 tools

All GA4 tools require a `property_id` (numeric, e.g. `123456789`).

`start_date` / `end_date` accept:
- Relative: `today`, `yesterday`, `NdaysAgo` (e.g. `7daysAgo`, `30daysAgo`, `90daysAgo`)
- Absolute: `YYYY-MM-DD`

---

### `ga4_run_report`

Fully custom GA4 report. Pass any dimensions and metrics from the [GA4 Dimensions & Metrics Explorer](https://ga-dev-tools.google/dimensions-metrics-explorer/).

| Parameter | Type | Required | Default |
|---|---|---|---|
| `property_id` | string | yes | — |
| `dimensions` | string[] | yes | — |
| `metrics` | string[] | yes | — |
| `start_date` | string | no | `30daysAgo` |
| `end_date` | string | no | `today` |
| `limit` | integer | no | `100` |

**Returns:** `{ rows: [{dim1: val, dim2: val, metric1: val, ...}], total, row_count, sampled }`

Common dimensions: `date` `sessionDefaultChannelGroup` `sessionSource` `sessionMedium` `country` `city` `deviceCategory` `pagePath` `pageTitle` `eventName` `newVsReturning` `browser` `operatingSystem` `language`

Common metrics: `sessions` `totalUsers` `newUsers` `activeUsers` `screenPageViews` `bounceRate` `engagementRate` `averageSessionDuration` `conversions` `totalRevenue` `transactions` `eventCount`

---

### `ga4_run_realtime_report`

Active users right now, with optional dimension breakdown.

| Parameter | Type | Required | Default |
|---|---|---|---|
| `property_id` | string | yes | — |
| `dimensions` | string[] | no | `["country"]` |
| `metrics` | string[] | no | `["activeUsers"]` |

Realtime dimensions: `country` `city` `deviceCategory` `unifiedScreenName` `eventName` `streamId`
Realtime metrics: `activeUsers` `eventCount` `conversions` `screenPageViews`

---

### `ga4_get_audience_overview`

Daily breakdown of `totalUsers`, `newUsers`, `sessions`, `bounceRate`, `averageSessionDuration`, `screenPageViewsPerSession`, `engagedSessions`, `engagementRate` — split by `newVsReturning`.

| Parameter | Type | Required | Default |
|---|---|---|---|
| `property_id` | string | yes | — |
| `start_date` | string | no | `30daysAgo` |
| `end_date` | string | no | `today` |

---

### `ga4_get_traffic_sources`

Sessions by `sessionDefaultChannelGroup` + `sessionSource` + `sessionMedium`.
Includes `sessions`, `totalUsers`, `newUsers`, `bounceRate`, `averageSessionDuration`, `conversions`, `totalRevenue`.

| Parameter | Type | Required | Default |
|---|---|---|---|
| `property_id` | string | yes | — |
| `start_date` | string | no | `30daysAgo` |
| `end_date` | string | no | `today` |
| `limit` | integer | no | `50` |

---

### `ga4_get_top_pages`

Top pages by `screenPageViews`. Returns `pagePath`, `pageTitle`, `totalUsers`, `averageSessionDuration`, `bounceRate`, `engagementRate`, `conversions`.

| Parameter | Type | Required | Default |
|---|---|---|---|
| `property_id` | string | yes | — |
| `start_date` | string | no | `30daysAgo` |
| `end_date` | string | no | `today` |
| `limit` | integer | no | `50` |

---

### `ga4_get_conversions`

Conversion events grouped by `eventName` and `sessionDefaultChannelGroup`.
Returns `conversions`, `totalRevenue`, `sessions`.

| Parameter | Type | Required | Default |
|---|---|---|---|
| `property_id` | string | yes | — |
| `start_date` | string | no | `30daysAgo` |
| `end_date` | string | no | `today` |

---

### `ga4_get_ecommerce`

E-commerce data by `date` and `itemName`: `totalRevenue`, `transactions`, `itemsPurchased`, `itemRevenue`, `averagePurchaseRevenue`, `purchaseToViewRate`.
Returns empty rows if the property has no e-commerce events.

| Parameter | Type | Required | Default |
|---|---|---|---|
| `property_id` | string | yes | — |
| `start_date` | string | no | `30daysAgo` |
| `end_date` | string | no | `today` |
| `limit` | integer | no | `50` |

---

### `ga4_get_geo_breakdown`

`country` + `city` breakdown: `sessions`, `totalUsers`, `newUsers`, `bounceRate`, `conversions`, `totalRevenue`.

| Parameter | Type | Required | Default |
|---|---|---|---|
| `property_id` | string | yes | — |
| `start_date` | string | no | `30daysAgo` |
| `end_date` | string | no | `today` |
| `limit` | integer | no | `50` |

---

### `ga4_get_device_breakdown`

`deviceCategory` + `operatingSystem` + `browser`: `sessions`, `totalUsers`, `bounceRate`, `engagementRate`, `conversions`, `averageSessionDuration`.

| Parameter | Type | Required | Default |
|---|---|---|---|
| `property_id` | string | yes | — |
| `start_date` | string | no | `30daysAgo` |
| `end_date` | string | no | `today` |

---

## Meta Ads tools

Meta `account_id` can be passed with or without the `act_` prefix.

`date_preset` values: `today` `yesterday` `last_3d` `last_7d` `last_14d` `last_28d` `last_30d` `last_90d` `this_month` `last_month` `this_quarter` `last_year` `lifetime`

Monetary values are returned in the account currency (not micros).

---

### `meta_get_ad_accounts`

List all ad accounts accessible with the current access token.
Call this first to get `account_id` values for other tools.

**Parameters:** none

**Returns:** `{ accounts: [{id, name, account_status, currency, timezone_name, amount_spent, balance, spend_cap}], total }`

`account_status` codes: `1`=Active, `2`=Disabled, `3`=Unsettled, `7`=Pending review, `9`=In grace period, `100`=Temporarily closed, `101`=Closed, `201`=Any active.

---

### `meta_get_campaigns`

Campaigns with budget, objective, status, and insights (spend, impressions, clicks, CTR, CPC, CPM, reach, frequency, `actions`, `action_values`, ROAS).

| Parameter | Type | Required | Default |
|---|---|---|---|
| `account_id` | string | yes | — |
| `date_preset` | string | no | `last_30d` |
| `status` | string[] | no | `["ACTIVE","PAUSED"]` |
| `limit` | integer | no | `50` |

**Returns:** `{ campaigns: [{...campaign_fields, insights: {...}}], total, date_preset }`

`actions` in insights is an array of `{ action_type, value }`. Common action types: `link_click`, `post_engagement`, `lead`, `purchase`, `add_to_cart`, `initiate_checkout`, `complete_registration`.

---

### `meta_get_adsets`

Ad sets with targeting summary, budget, optimization goal, and insights.

| Parameter | Type | Required | Default |
|---|---|---|---|
| `account_id` | string | yes | — |
| `campaign_id` | string | no | — |
| `date_preset` | string | no | `last_30d` |
| `limit` | integer | no | `50` |

**Returns:** `{ adsets: [{...adset_fields, insights: {...}}], total }`

`targeting` field contains: `age_min`, `age_max`, `genders`, `geo_locations`, `interests`, `behaviors`, `custom_audiences`, `excluded_custom_audiences`, `publisher_platforms`, `device_platforms`.

---

### `meta_get_ads`

Individual ads with creative reference and insights.

| Parameter | Type | Required | Default |
|---|---|---|---|
| `account_id` | string | yes | — |
| `campaign_id` | string | no | — |
| `adset_id` | string | no | — |
| `date_preset` | string | no | `last_30d` |
| `limit` | integer | no | `50` |

**Returns:** `{ ads: [{...ad_fields, insights: {...}}], total }`

To get creative image/copy details from an ad, use the `creative.id` returned here and call `meta_get_creatives`.

---

### `meta_get_insights`

Aggregated insights with optional breakdowns. More flexible than the per-object insights attached to campaigns/adsets/ads.

| Parameter | Type | Required | Default |
|---|---|---|---|
| `account_id` | string | yes | — |
| `object_id` | string | no | — |
| `object_type` | string | no | `account` |
| `date_preset` | string | no | `last_30d` |
| `breakdowns` | string[] | no | none |
| `limit` | integer | no | `100` |

`object_type`: `account` \| `campaign` \| `adset` \| `ad`

`breakdowns` options: `age` `gender` `country` `region` `impression_device` `placement` `platform_position` `publisher_platform`

Note: some breakdown combinations are incompatible with each other (Meta API limitation). Use one breakdown at a time when unsure.

**Returns:** `{ insights: [...], total, date_preset, breakdowns }`

Fields in each row: `campaign_id/name`, `adset_id/name`, `ad_id/name`, `impressions`, `clicks`, `spend`, `reach`, `frequency`, `ctr`, `cpc`, `cpm`, `cpp`, `actions`, `action_values`, `cost_per_action_type`, `date_start`, `date_stop`.

---

### `meta_get_custom_audiences`

Custom and lookalike audiences with size estimates and source information.

| Parameter | Type | Required | Default |
|---|---|---|---|
| `account_id` | string | yes | — |
| `limit` | integer | no | `50` |

**Returns:** `{ audiences: [{id, name, description, subtype, approximate_count_lower_bound, approximate_count_upper_bound, data_source, lookalike_spec, retention_days}], total }`

`subtype` values: `CUSTOM` `WEBSITE` `APP` `OFFLINE_CONVERSION` `CLAIM` `PARTNER` `MANAGED` `VIDEO` `LOOKALIKE` `ENGAGEMENT` `BAG_OF_ACCOUNTS` `STUDY_RULE_AUDIENCE` `FOX`

---

### `meta_get_pixels`

Meta Pixels (Facebook Pixel) installed on the ad account with last fire time.

| Parameter | Type | Required |
|---|---|---|
| `account_id` | string | yes |

**Returns:** `{ pixels: [{id, name, code, creation_time, last_fired_time, is_unavailable}], total }`

---

### `meta_get_creatives`

Ad creative assets: title, body text, image URL, CTA type, destination URL.

| Parameter | Type | Required | Default |
|---|---|---|---|
| `account_id` | string | yes | — |
| `limit` | integer | no | `50` |

**Returns:** `{ creatives: [{id, name, title, body, object_type, image_url, thumbnail_url, call_to_action_type, link_url}], total }`

---

## Common workflows

**"What's my Google Ads account performance this month?"**
1. `google_ads_list_customers` → get `customer_id`
2. `google_ads_get_account_metrics` with `date_range: THIS_MONTH`

**"Which Meta campaigns have the best ROAS?"**
1. `meta_get_ad_accounts` → get `account_id`
2. `meta_get_campaigns` with `date_preset: last_30d`
3. Look at `insights.action_values` (purchase value) divided by `insights.spend`

**"Where is my website traffic coming from?"**
1. `ga4_get_traffic_sources` with your `property_id`

**"What search terms are wasting budget?"**
1. `google_ads_get_search_terms` → find terms with clicks but 0 conversions and high spend

**"Compare device performance across Google and Meta"**
1. `ga4_get_device_breakdown` for website sessions by device
2. `meta_get_insights` with `breakdowns: ["impression_device"]`
3. `google_ads_query` with `SELECT segments.device, metrics.* FROM campaign WHERE ...`

**"Full account audit"**
1. `google_ads_get_account_metrics` — top-line numbers
2. `google_ads_get_campaigns` — campaign breakdown
3. `google_ads_get_keywords` with low quality score filter in follow-up `google_ads_query`
4. `google_ads_get_search_terms` — find irrelevant terms
5. `ga4_get_traffic_sources` — paid vs organic split
6. `meta_get_campaigns` + `meta_get_insights` with age/gender breakdowns

---

## Error handling

All tools return errors inside the MCP `content` array with `isError: true`. The error message is the raw exception message from the API. Common causes:

- `PERMISSION_DENIED` — the OAuth token doesn't have access to that customer/account
- `INVALID_CUSTOMER_ID` — wrong format or account doesn't exist
- `quota exceeded` — Google Ads API daily quota hit
- `(#100)` — Meta: invalid parameter or missing permission scope
- `Token expired` — re-run `php auth.php` to refresh credentials
