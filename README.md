# marketing-mcp

MCP server that exposes Google Ads, Google Analytics 4, and Meta Ads as tools for LLMs.
Built in PHP. Supports two transport modes: **stdio** (local, single user) and **HTTP** (Docker, multi-user).

---

## Table of contents

- [Server setup (Docker + HTTP)](#server-setup-docker--http)
- [Local setup (stdio)](#local-setup-stdio)
- [Authorizing platforms from Claude](#authorizing-platforms-from-claude)
- [User management CLI](#user-management-cli)
- [Tools reference](#tools-reference)

---

## Server setup (Docker + HTTP)

The HTTP mode lets multiple users connect from any device using their own API credentials. SSL is handled by Nginx Proxy Manager or any other reverse proxy.

### 1 — Start the container

```bash
git clone https://github.com/and-ri/marketing-mcp.git
cd marketing-mcp
docker compose up -d --build
```

The image is built locally on the host from the cloned sources (`pull_policy: build` in `docker-compose.yml`) — nothing is pulled from a registry except the official `php:8.5-cli` and `composer:2` images. Google APIs are called over REST, so no PHP extensions need to be compiled from source.

The server listens on port **8080**. In Nginx Proxy Manager: add a Proxy Host → forward to `localhost:8080` → enable SSL as usual.

### 2 — Create a user

```bash
docker compose exec mcp php users.php add alice
```

Prints a Bearer token. Keep it — this is the credential for Claude.

### 3 — Connect Claude

Add to `~/.claude.json` (global) or `.mcp.json` (project-level):

```json
{
  "mcpServers": {
    "marketing": {
      "type": "http",
      "url": "https://mcp.example.com/mcp",
      "headers": {
        "Authorization": "Bearer <token from step 2>"
      }
    }
  }
}
```

### 4 — Authorize platforms

Once connected, tell Claude to call `auth_status` — it will report what's missing and guide you through the setup. Or just ask: *"set up Google Ads for me"* and Claude will walk through each step.

See [Authorizing platforms from Claude](#authorizing-platforms-from-claude) for details.

### 5 — Maintenance

```bash
docker compose logs -f mcp                  # live logs
git pull && docker compose up -d --build   # update: pull sources and rebuild the image
docker compose exec mcp php users.php list  # list all users
```

---

## Local setup (stdio)

For single-user local use without Docker.

```bash
composer install
php auth.php         # interactive OAuth2 flow — opens browser, writes .env
```

MCP client config:

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

## Authorizing platforms from Claude

In HTTP mode, credentials are stored per-user in SQLite. Authorization happens through MCP tools — no terminal access needed.

### How it works

1. Call **`auth_start_google`** or **`auth_start_meta`** — returns an OAuth URL
2. Open the URL in your browser and authorize access
3. The browser shows "Authorized! You can close this tab."
4. Call **`auth_finish_google`** or **`auth_finish_meta`** — saves the credentials

The server's OAuth callback endpoint is `https://yourdomain.com/oauth/callback`. This URL must be registered in both Google and Meta developer consoles (the tool descriptions contain exact instructions for where to add it).

### Auth tools

| Tool | What it does |
|---|---|
| `auth_status` | Shows which platforms are configured and what's missing |
| `auth_start_google` | Starts Google OAuth2. Tool description contains full setup guide for Google Cloud Console |
| `auth_finish_google` | Completes Google auth, saves refresh token |
| `auth_start_meta` | Starts Meta OAuth2. Tool description contains full setup guide for the Meta developer portal |
| `auth_finish_meta` | Completes Meta auth, saves long-lived token (~60 days) |
| `auth_start_tiktok` / `auth_finish_tiktok` | TikTok Ads OAuth2 flow |
| `auth_set_clarity` | Saves the Microsoft Clarity API token (single step — Clarity has no OAuth) |
| `auth_set_rybbit` | Saves self-hosted Rybbit `base_url` + `api_key` (single step — Rybbit has no OAuth) |
| `auth_set_bing_webmaster` | Saves the Bing Webmaster Tools API key (single step) |
| `auth_set_serpbear` | Saves self-hosted SerpBear `base_url` + `api_key` (single step) |

> **Token-based integrations** (Microsoft Clarity, Rybbit, Bing Webmaster, SerpBear) don't use OAuth — there's no browser step. Just call the matching `auth_set_*` tool with the token(s) and you're done.
>
> **Google Search Console** shares the Google OAuth token with Ads and GA4 (scope `webmasters.readonly`). If you authorized Google before Search Console support was added, re-run `auth_start_google` + `auth_finish_google` once.

### Google — what you'll need

Before calling `auth_start_google`:

1. **Google Cloud project** — [console.cloud.google.com](https://console.cloud.google.com)
2. **APIs enabled** — Google Ads API + Google Analytics Data API + Google Search Console API
3. **OAuth client** — type: *Web application*, redirect URI: `https://yourdomain.com/oauth/callback`
4. **Developer token** — [ads.google.com/aw/apicenter](https://ads.google.com/aw/apicenter)

The `auth_start_google` tool description includes step-by-step instructions for all of the above. Claude can read them and guide you interactively.

### Meta — what you'll need

Before calling `auth_start_meta`:

1. **Meta developer app** — [developers.facebook.com](https://developers.facebook.com), type: Business
2. **Marketing API product** added to the app
3. **Facebook Login product** added, redirect URI `https://yourdomain.com/oauth/callback` registered
4. **App ID and App Secret** — Settings → Basic

The `auth_start_meta` tool description includes step-by-step instructions. Meta tokens expire in ~60 days — re-authorize with `auth_start_meta` + `auth_finish_meta` when needed.

---

## User management CLI

Run inside the Docker container:

```bash
# Create user — prints Bearer token
docker compose exec mcp php users.php add <name>

# List all users and tokens
docker compose exec mcp php users.php list

# Show a user's env vars (values masked)
docker compose exec mcp php users.php env:show <name>

# Set a single env var manually
docker compose exec mcp php users.php env:set <name> META_ACCESS_TOKEN EAAxxxxx

# Import all vars from a .env file
docker compose exec -T mcp php users.php env:import <name> < credentials.env

# Reset a compromised token
docker compose exec mcp php users.php token:reset <name>

# Remove a user and all their credentials
docker compose exec mcp php users.php remove <name>
```

Credentials stored per user (same key names as `.env.example`):

| Platform | Keys |
|---|---|
| Google Ads | `GOOGLE_ADS_DEVELOPER_TOKEN`, `GOOGLE_ADS_CLIENT_ID`, `GOOGLE_ADS_CLIENT_SECRET`, `GOOGLE_ADS_REFRESH_TOKEN`, `GOOGLE_ADS_LOGIN_CUSTOMER_ID` *(MCC only)* |
| Google Analytics 4 | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REFRESH_TOKEN` |
| Meta Ads | `META_APP_ID`, `META_APP_SECRET`, `META_ACCESS_TOKEN` |

---

## Tools reference

### Finding account IDs

| Platform | How to find IDs |
|---|---|
| Google Ads `customer_id` | 10-digit number, no dashes. Call `google_ads_list_customers` first. |
| GA4 `property_id` | Numeric ID — Analytics → Admin → Property Settings. E.g. `123456789`. |
| Meta `account_id` | Numeric ID, with or without `act_` prefix. Call `meta_get_ad_accounts` first. |

---

## Google Ads tools

`date_range` values: `TODAY` `YESTERDAY` `LAST_7_DAYS` `LAST_14_DAYS` `LAST_30_DAYS` `THIS_MONTH` `LAST_MONTH` `THIS_QUARTER` `LAST_QUARTER` `THIS_YEAR` `LAST_YEAR`

Monetary values are in **micros** — divide by 1,000,000 for the actual amount.

---

### `google_ads_list_customers`

List all accessible Google Ads accounts. Call this first to get `customer_id` values.

**Parameters:** none

**Returns:** `{ customers: [{ customer_id, resource_name }], total }`

---

### `google_ads_get_campaigns`

Campaigns with spend, clicks, impressions, CTR, CPC, CPM, conversions, and conversion value.

| Parameter | Type | Required | Default |
|---|---|---|---|
| `customer_id` | string | yes | — |
| `date_range` | string | no | `LAST_30_DAYS` |
| `status` | string | no | `ALL` — `ENABLED` \| `PAUSED` \| `REMOVED` \| `ALL` |

---

### `google_ads_get_ad_groups`

Ad groups with bids and metrics. Optionally scoped to one campaign.

| Parameter | Type | Required | Default |
|---|---|---|---|
| `customer_id` | string | yes | — |
| `campaign_id` | string | no | — |
| `date_range` | string | no | `LAST_30_DAYS` |

---

### `google_ads_get_keywords`

Keywords with quality score (1–10), match type, effective CPC bid, impression share.

| Parameter | Type | Required | Default |
|---|---|---|---|
| `customer_id` | string | yes | — |
| `campaign_id` | string | no | — |
| `ad_group_id` | string | no | — |
| `date_range` | string | no | `LAST_30_DAYS` |
| `limit` | integer | no | `200` |

---

### `google_ads_get_search_terms`

What users actually searched to trigger your ads. Use for negative keyword discovery.

| Parameter | Type | Required | Default |
|---|---|---|---|
| `customer_id` | string | yes | — |
| `campaign_id` | string | no | — |
| `date_range` | string | no | `LAST_30_DAYS` |
| `limit` | integer | no | `200` |

---

### `google_ads_get_ads`

Individual ads with creative text (RSA headlines/descriptions, ETA fields) and approval status.

| Parameter | Type | Required | Default |
|---|---|---|---|
| `customer_id` | string | yes | — |
| `campaign_id` | string | no | — |
| `ad_group_id` | string | no | — |
| `date_range` | string | no | `LAST_30_DAYS` |
| `limit` | integer | no | `100` |

---

### `google_ads_get_geo_performance`

Performance by geographic location (`country_criterion_id`).

| Parameter | Type | Required | Default |
|---|---|---|---|
| `customer_id` | string | yes | — |
| `campaign_id` | string | no | — |
| `date_range` | string | no | `LAST_30_DAYS` |
| `limit` | integer | no | `100` |

---

### `google_ads_get_account_metrics`

Top-line account summary: total spend, clicks, impressions, conversions, impression share, lost IS (budget and rank).

| Parameter | Type | Required | Default |
|---|---|---|---|
| `customer_id` | string | yes | — |
| `date_range` | string | no | `LAST_30_DAYS` |

---

### `google_ads_query`

Execute any [GAQL](https://developers.google.com/google-ads/api/docs/query/overview) query. Use when other tools don't cover the report you need (shopping, video, change history, audiences, etc.).

| Parameter | Type | Required |
|---|---|---|
| `customer_id` | string | yes |
| `query` | string | yes |

```sql
-- Change history
SELECT change_event.change_date_time, change_event.change_resource_type, change_event.user_email
FROM change_event WHERE change_event.change_date_time DURING LAST_14_DAYS
ORDER BY change_event.change_date_time DESC LIMIT 50

-- Shopping performance
SELECT shopping_performance_view.resource_name, metrics.clicks, metrics.cost_micros
FROM shopping_performance_view WHERE segments.date DURING LAST_30_DAYS
```

---

### `google_ads_get_change_history`

Account change history — who changed what and when (campaigns, ad groups, bids, budgets, ads, criteria). Google retains only the **last 30 days**.

| Parameter | Type | Required | Default |
|---|---|---|---|
| `customer_id` | string | yes | — |
| `days` | integer | no | `14` (max 30) |
| `resource_type` | string | no | — e.g. `CAMPAIGN`, `AD_GROUP`, `AD_GROUP_AD`, `CAMPAIGN_BUDGET` |
| `limit` | integer | no | `500` (max 10000) |

**Returns:** `{ changes: [...], total, days }`

---

### `google_ads_get_negative_keyword_lists`

Shared negative keyword lists (shared sets of type `NEGATIVE_KEYWORDS`): the lists, their keyword members, and which campaigns each is attached to.

| Parameter | Type | Required | Default |
|---|---|---|---|
| `customer_id` | string | yes | — |

**Returns:** `{ lists: [...], members: [...], attachments: [...], total_lists }`

---

### `google_ads_get_negative_keywords`

Negative keywords applied directly at the campaign and ad group levels (excludes shared lists).

| Parameter | Type | Required | Default |
|---|---|---|---|
| `customer_id` | string | yes | — |
| `campaign_id` | string | no | — |
| `limit` | integer | no | `500` |

**Returns:** `{ campaign_level: [...], ad_group_level: [...], total }`

---

### `google_ads_get_audiences`

Audience segments / user lists, including custom-uploaded Customer Match lists (`user_list.type = CRM_BASED`) with match rates, plus remarketing, similar, rule-based lists and combined audiences. Shows sizes, membership status, and eligibility.

| Parameter | Type | Required | Default |
|---|---|---|---|
| `customer_id` | string | yes | — |
| `list_type` | string | no | — e.g. `CRM_BASED`, `REMARKETING`, `SIMILAR`, `RULE_BASED`, `LOGICAL` |

**Returns:** `{ user_lists: [...], audiences: [...], total_user_lists }`

---

### `google_ads_get_audience_performance`

Performance of audience segments attached to campaigns and ad groups (`campaign_audience_view`, `ad_group_audience_view`) with impressions, clicks, cost, and conversions per audience.

| Parameter | Type | Required | Default |
|---|---|---|---|
| `customer_id` | string | yes | — |
| `campaign_id` | string | no | — |
| `date_range` | string | no | `LAST_30_DAYS` |
| `limit` | integer | no | `200` |

**Returns:** `{ ad_group_level: [...], campaign_level: [...], total, date_range }`

---

## Google Analytics 4 tools

`start_date` / `end_date`: relative (`today`, `yesterday`, `7daysAgo`, `30daysAgo`) or absolute `YYYY-MM-DD`.

---

### `ga4_run_report`

Custom report with any dimensions and metrics from the [GA4 explorer](https://ga-dev-tools.google/dimensions-metrics-explorer/).

| Parameter | Type | Required | Default |
|---|---|---|---|
| `property_id` | string | yes | — |
| `dimensions` | string[] | yes | — |
| `metrics` | string[] | yes | — |
| `start_date` | string | no | `30daysAgo` |
| `end_date` | string | no | `today` |
| `limit` | integer | no | `100` |

---

### `ga4_run_realtime_report`

Active users right now with optional dimension breakdown.

| Parameter | Type | Required | Default |
|---|---|---|---|
| `property_id` | string | yes | — |
| `dimensions` | string[] | no | `["country"]` |
| `metrics` | string[] | no | `["activeUsers"]` |

---

### `ga4_get_audience_overview`

Daily breakdown: `totalUsers`, `newUsers`, `sessions`, `bounceRate`, `averageSessionDuration`, `engagedSessions`, `engagementRate` split by `newVsReturning`.

| Parameter | Type | Required | Default |
|---|---|---|---|
| `property_id` | string | yes | — |
| `start_date` | string | no | `30daysAgo` |
| `end_date` | string | no | `today` |

---

### `ga4_get_traffic_sources`

Sessions by channel group, source, and medium. Includes `sessions`, `totalUsers`, `bounceRate`, `conversions`, `totalRevenue`.

| Parameter | Type | Required | Default |
|---|---|---|---|
| `property_id` | string | yes | — |
| `start_date` | string | no | `30daysAgo` |
| `end_date` | string | no | `today` |
| `limit` | integer | no | `50` |

---

### `ga4_get_top_pages`

Top pages by views. Returns `pagePath`, `pageTitle`, `totalUsers`, `bounceRate`, `engagementRate`, `conversions`.

| Parameter | Type | Required | Default |
|---|---|---|---|
| `property_id` | string | yes | — |
| `start_date` | string | no | `30daysAgo` |
| `end_date` | string | no | `today` |
| `limit` | integer | no | `50` |

---

### `ga4_get_conversions`

Conversion events by `eventName` and channel group. Returns `conversions`, `totalRevenue`, `sessions`.

| Parameter | Type | Required | Default |
|---|---|---|---|
| `property_id` | string | yes | — |
| `start_date` | string | no | `30daysAgo` |
| `end_date` | string | no | `today` |

---

### `ga4_get_ecommerce`

Revenue, transactions, items sold, average order value. Returns empty if the property has no e-commerce events.

| Parameter | Type | Required | Default |
|---|---|---|---|
| `property_id` | string | yes | — |
| `start_date` | string | no | `30daysAgo` |
| `end_date` | string | no | `today` |
| `limit` | integer | no | `50` |

---

### `ga4_get_geo_breakdown`

Traffic by country and city.

| Parameter | Type | Required | Default |
|---|---|---|---|
| `property_id` | string | yes | — |
| `start_date` | string | no | `30daysAgo` |
| `end_date` | string | no | `today` |
| `limit` | integer | no | `50` |

---

### `ga4_get_device_breakdown`

Traffic by device category, OS, and browser.

| Parameter | Type | Required | Default |
|---|---|---|---|
| `property_id` | string | yes | — |
| `start_date` | string | no | `30daysAgo` |
| `end_date` | string | no | `today` |

---

## Meta Ads tools

`account_id` accepts numeric ID with or without `act_` prefix.

`date_preset`: `today` `yesterday` `last_3d` `last_7d` `last_14d` `last_28d` `last_30d` `last_90d` `this_month` `last_month` `this_quarter` `last_year` `lifetime`

Monetary values are in account currency (not micros).

---

### `meta_get_ad_accounts`

List all accessible ad accounts. Call this first to get `account_id` values.

**Parameters:** none

`account_status` codes: `1`=Active, `2`=Disabled, `3`=Unsettled, `7`=Pending review, `101`=Closed.

---

### `meta_get_campaigns`

Campaigns with budget, objective, and insights (spend, reach, impressions, clicks, CTR, CPC, ROAS, `actions`).

| Parameter | Type | Required | Default |
|---|---|---|---|
| `account_id` | string | yes | — |
| `date_preset` | string | no | `last_30d` |
| `status` | string[] | no | `["ACTIVE","PAUSED"]` |
| `limit` | integer | no | `50` |

`actions` in insights is `[{ action_type, value }]`. Common types: `link_click`, `lead`, `purchase`, `add_to_cart`, `initiate_checkout`, `complete_registration`.

---

### `meta_get_adsets`

Ad sets with targeting (`age_min/max`, `genders`, `geo_locations`, `interests`, `custom_audiences`, `publisher_platforms`) and insights.

| Parameter | Type | Required | Default |
|---|---|---|---|
| `account_id` | string | yes | — |
| `campaign_id` | string | no | — |
| `date_preset` | string | no | `last_30d` |
| `limit` | integer | no | `50` |

---

### `meta_get_ads`

Individual ads with creative reference and insights. Use `creative.id` from results with `meta_get_creatives` to get image/copy.

| Parameter | Type | Required | Default |
|---|---|---|---|
| `account_id` | string | yes | — |
| `campaign_id` | string | no | — |
| `adset_id` | string | no | — |
| `date_preset` | string | no | `last_30d` |
| `limit` | integer | no | `50` |

---

### `meta_get_insights`

Aggregated insights with optional breakdowns. More flexible than the per-object insights on campaigns/adsets/ads.

| Parameter | Type | Required | Default |
|---|---|---|---|
| `account_id` | string | yes | — |
| `object_id` | string | no | — |
| `object_type` | string | no | `account` — `account` \| `campaign` \| `adset` \| `ad` |
| `date_preset` | string | no | `last_30d` |
| `breakdowns` | string[] | no | none |
| `limit` | integer | no | `100` |

`breakdowns`: `age` `gender` `country` `region` `impression_device` `placement` `platform_position` `publisher_platform`

Note: some breakdown combinations are incompatible. Use one at a time when unsure.

---

### `meta_get_custom_audiences`

Custom and lookalike audiences with size estimates and data source.

| Parameter | Type | Required | Default |
|---|---|---|---|
| `account_id` | string | yes | — |
| `limit` | integer | no | `50` |

`subtype` values: `CUSTOM` `WEBSITE` `APP` `LOOKALIKE` `ENGAGEMENT` `VIDEO` `OFFLINE_CONVERSION`

---

### `meta_get_pixels`

Meta Pixels with last fire time.

| Parameter | Type | Required |
|---|---|---|
| `account_id` | string | yes |

---

### `meta_get_creatives`

Ad creative assets: title, body, image URL, CTA type, destination URL.

| Parameter | Type | Required | Default |
|---|---|---|---|
| `account_id` | string | yes | — |
| `limit` | integer | no | `50` |

---

## Microsoft Clarity tools

Behavioral analytics that complement GA4 — Clarity surfaces UX friction (rage clicks, dead clicks, quickbacks, scroll depth, JS errors) that GA4 doesn't capture. Auth is a static project token (`CLARITY_API_TOKEN`), generated in Clarity → **Settings → Data Export → Generate new API token**. No OAuth flow.

> **Constraints (Clarity API):** data covers only the **last 1–3 days**, max **10 requests per project per day**, response capped at **1000 rows** (no pagination), results in UTC.

### `clarity_get_insights`

Live insights for the project: traffic, scroll depth, engagement time, dead/rage/quickback clicks, excessive scroll, and script errors. Optionally broken down by up to three dimensions.

| Parameter | Type | Required | Default |
|---|---|---|---|
| `num_of_days` | integer | no | `1` — `1` \| `2` \| `3` |
| `dimension1` | string | no | — `Browser` \| `Device` \| `Country/Region` \| `OS` \| `Source` \| `Medium` \| `Campaign` \| `Channel` \| `URL` |
| `dimension2` | string | no | — second breakdown |
| `dimension3` | string | no | — third breakdown |

**Returns:** `{ num_of_days, metrics: [{ metricName, information: [...] }] }`

---

## Rybbit tools

Read-only analytics from a **self-hosted** [Rybbit](https://rybbit.com) instance. Set `RYBBIT_BASE_URL` (your instance, no trailing slash) and `RYBBIT_API_KEY` (Settings → Account → API Keys). All tools take a `site_id` plus an optional time range (`start_date`, `end_date`, `time_zone`) and `filters` (JSON-encoded array).

### `rybbit_get_overview`

Top-line metrics: sessions, pageviews, users, pages per session, bounce rate, session duration.

### `rybbit_get_overview_timeseries`

Overview metrics bucketed over time. Adds `bucket` (`minute` \| `five_minutes` \| `hour` \| `day` \| `week` \| `month`, default `day`).

### `rybbit_get_sessions`

Paginated session list with geo, device, browser, source, pages, duration. Adds `page`, `limit`, optional `user_id`.

### `rybbit_get_event_names`

Custom event names with occurrence counts — track conversions and key actions.

### `rybbit_get_performance`

Core Web Vitals (LCP, CLS, INP, FCP, TTFB) at p50/p75/p90/p99. Useful for landing page quality.

### `rybbit_get_errors`

JavaScript errors grouped by name with counts, session counts, and percentages. Adds `page`, `limit`.

### `rybbit_get_goals`

Conversion goals with total conversions, sessions, and conversion rate. Adds `page`, `page_size`.

| Common Parameter | Type | Required | Default |
|---|---|---|---|
| `site_id` | string | yes | — |
| `start_date` | string | no | — ISO date |
| `end_date` | string | no | — ISO date |
| `time_zone` | string | no | UTC |
| `filters` | string | no | — JSON array |

---

## Google Search Console tools

Organic Google search data. Uses the same Google OAuth token as Ads/GA4 (scope `https://www.googleapis.com/auth/webmasters.readonly`). `site_url` is the property exactly as returned by `gsc_list_sites`: `https://example.com/` (URL-prefix) or `sc-domain:example.com` (domain property).

### `gsc_list_sites`

Properties available to the account, with permission level.

### `gsc_search_analytics`

Clicks, impressions, CTR (percent) and average position.

| Parameter | Type | Required | Default |
|---|---|---|---|
| `site_url` | string | yes | — |
| `start_date` / `end_date` | string | yes | — `YYYY-MM-DD` |
| `dimensions` | array | no | `["query"]` — `query` \| `page` \| `country` \| `device` \| `date` \| `searchAppearance` |
| `search_type` | string | no | `web` — `image` \| `video` \| `news` \| `discover` \| `googleNews` |
| `filters` | array | no | — `[{dimension, operator, expression}]`, AND-combined |
| `row_limit` | integer | no | `1000` (max 25000) |
| `start_row` | integer | no | `0` |
| `data_state` | string | no | `final` — `all` includes fresh data |

### `gsc_list_sitemaps`

Submitted sitemaps with last submitted/downloaded dates, warnings, errors and indexed counts.

### `gsc_inspect_url`

URL Inspection API: index status, coverage, last crawl, Google canonical, mobile usability, rich results. Params: `site_url`, `inspection_url`, `language_code` (default `en-US`). Quota ~2000/day per property.

---

## Bing Webmaster tools

Bing organic search data (also covers Yahoo/DuckDuckGo syndication). Auth is a static API key (`BING_WEBMASTER_API_KEY`) from Bing Webmaster Tools → **Settings → API access → API Key**. Bing returns full history; the stats tools filter client-side by optional `start_date` / `end_date` and sort by impressions with a `limit` (default 100, `0` = all). `site_url` is as listed by `bing_list_sites`.

| Tool | What it returns |
|---|---|
| `bing_list_sites` | Verified sites |
| `bing_get_traffic_stats` | Daily impressions and clicks |
| `bing_get_query_stats` | Top queries: impressions, clicks, avg click/impression position (weekly rows) |
| `bing_get_page_stats` | Top pages with the same metrics |
| `bing_get_query_page_stats` | Pages ranking for one `query` |
| `bing_get_page_query_stats` | Queries driving traffic to one `page` |
| `bing_get_crawl_stats` | Daily crawl: crawled pages, errors, in-index, blocked by robots.txt, HTTP codes |
| `bing_get_crawl_issues` | URLs with crawl issues |
| `bing_get_link_counts` | Inbound link counts per page (`page` index, from 0) |
| `bing_get_url_info` | Index details for one `url` |
| `bing_get_sitemaps` | Submitted sitemaps/feeds and their status |
| `bing_get_keyword_stats` | Keyword research: weekly Bing impressions for `query` in `country` / `language` |
| `bing_get_url_submission_quota` | Remaining daily/monthly URL submission quota |

---

## SerpBear tools

Keyword rank tracking from a **self-hosted** [SerpBear](https://docs.serpbear.com) instance. Set `SERPBEAR_BASE_URL` (no trailing slash) and `SERPBEAR_API_KEY` (the `APIKEY` value from SerpBear's `.env`). Keyword responses drop the heavy raw SERP snapshot (`lastResult`) and trim `history` to the last 30 days by default — override with `include_serp_result: true` / `history_days: 0`.

| Tool | What it does |
|---|---|
| `serpbear_get_domains` | Tracked domains with keyword counts and last update |
| `serpbear_get_keywords` | All keywords for a `domain`: position, ranking URL, device, country, tags, history. Optional `tag`, `device` filters |
| `serpbear_get_keyword` | One keyword by `id` |
| `serpbear_refresh_keywords` | Re-scrape positions now for keyword `ids` (uses scraper credits) |

---

## Common workflows

**"What's my Google Ads performance this month?"**
1. `google_ads_list_customers` → get `customer_id`
2. `google_ads_get_account_metrics` with `date_range: THIS_MONTH`
3. `google_ads_get_campaigns` for breakdown by campaign

**"Which Meta campaigns have the best ROAS?"**
1. `meta_get_ad_accounts` → get `account_id`
2. `meta_get_campaigns` → look at `insights.action_values[purchase] / insights.spend`

**"Where is my website traffic coming from?"**
1. `ga4_get_traffic_sources` with your `property_id`

**"What search terms are wasting budget?"**
1. `google_ads_get_search_terms` → find terms with high spend and 0 conversions

**"Full cross-platform audit"**
1. `google_ads_get_account_metrics` — top-line spend/conversions
2. `google_ads_get_campaigns` + `google_ads_get_keywords` — quality scores, impression share
3. `google_ads_get_search_terms` — irrelevant queries
4. `meta_get_campaigns` + `meta_get_insights` with `breakdowns: ["age"]` and `["placement"]`
5. `ga4_get_traffic_sources` — paid vs organic split
6. `ga4_get_conversions` — which events actually convert

---

## Error reference

All tools return errors inside the MCP `content` array (with `isError: true`). Common causes:

| Error | Cause | Fix |
|---|---|---|
| `PERMISSION_DENIED` | OAuth token can't access that account | Check account access in Google Ads or use `google_ads_list_customers` to see what's accessible |
| `INVALID_CUSTOMER_ID` | Wrong format or account doesn't exist | Use 10-digit format without dashes |
| `quota exceeded` | Google Ads API daily quota hit | Wait 24 hours or request a higher quota |
| `(#100)` | Meta: invalid parameter or missing permission | Check scope — ensure `ads_read`, `ads_management`, `business_management` were granted |
| `OAuth2 token expired` | Google refresh token revoked or expired | Call `auth_start_google` + `auth_finish_google` |
| `Error validating access token` | Meta token expired (~60 days) | Call `auth_start_meta` + `auth_finish_meta` |
| `Developer token is not approved` | Google Ads dev token has Basic Access | Apply for Standard Access at ads.google.com/aw/apicenter |
