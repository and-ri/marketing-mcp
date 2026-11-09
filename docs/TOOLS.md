# Tools reference

All 113 tools exposed by marketing-mcp, grouped by platform. Claude sees the full input schema of every tool, so this page lists what each tool is for and its key parameters rather than every option.

Everything is **read-only** except two tools: `rybbit_create_annotation` adds a note to the Rybbit timeline, and `serpbear_refresh_keywords` makes SerpBear re-check positions, which spends scraper credits.

- [Authorization](#authorization) · 11
- [Google Ads](#google-ads) · 17
- [Google Analytics 4](#google-analytics-4) · 9
- [Meta Ads](#meta-ads) · 10
- [TikTok Ads](#tiktok-ads) · 5
- [Google Search Console](#google-search-console) · 4
- [Bing Webmaster Tools](#bing-webmaster-tools) · 13
- [SerpBear](#serpbear) · 4
- [Microsoft Clarity](#microsoft-clarity) · 1
- [Rybbit](#rybbit) · 39
- [Errors](#errors)

---

## Authorization

HTTP mode only. Credentials are stored per user in SQLite on the server.

| Tool | What it does |
|---|---|
| `auth_status` | What is configured, what is missing, and the next step for each platform |
| `auth_start_google` / `auth_finish_google` | OAuth for Google Ads + GA4 + Search Console (one token). The tool description is a full Google Cloud setup guide |
| `auth_start_meta` / `auth_finish_meta` | OAuth for Meta Ads. Long-lived token, ~60 days |
| `auth_start_tiktok` / `auth_finish_tiktok` | OAuth for TikTok Ads |
| `auth_set_clarity` | Save a Clarity Data Export token |
| `auth_set_rybbit` | Save Rybbit `base_url` + `api_key` |
| `auth_set_bing_webmaster` | Save a Bing Webmaster API key |
| `auth_set_serpbear` | Save SerpBear `base_url` + `api_key` |

OAuth flow: `auth_start_*` returns a URL → you sign in in the browser → the page says *Authorized!* → `auth_finish_*` saves the token. The callback is `https://<your-domain>/oauth/callback`; register it in the Google / Meta / TikTok app.

---

## Google Ads

Start with `google_ads_list_customers` to get the 10-digit `customer_id` (no dashes). Money values are in **micros** (÷ 1,000,000).

`date_range`: `TODAY` `YESTERDAY` `LAST_7_DAYS` `LAST_14_DAYS` `LAST_30_DAYS` `THIS_MONTH` `LAST_MONTH` `THIS_QUARTER` `LAST_QUARTER` `THIS_YEAR` `LAST_YEAR` (default `LAST_30_DAYS`).

| Tool | What it returns | Key params |
|---|---|---|
| `google_ads_list_customers` | Accessible accounts | — |
| `google_ads_get_account_metrics` | Spend, clicks, conversions, ROAS, impression share, IS lost to budget/rank | `date_range` |
| `google_ads_get_campaigns` | Campaigns with budget and full metrics | `status` |
| `google_ads_get_ad_groups` | Ad groups with bids and metrics | `campaign_id` |
| `google_ads_get_keywords` | Keywords: quality score, match type, CPC bid, impression share | `campaign_id`, `ad_group_id` |
| `google_ads_get_search_terms` | Real search queries that triggered ads — source of negatives | `campaign_id`, `limit` |
| `google_ads_get_ads` | Ads with RSA headlines/descriptions and approval status | `ad_group_id` |
| `google_ads_get_asset_performance` | RSA / PMax assets with labels BEST / GOOD / LOW | `asset_type` |
| `google_ads_get_segments` | Performance by device, day of week or hour of day | `segment` |
| `google_ads_get_geo_performance` | Performance by location | `campaign_id` |
| `google_ads_get_conversions` | Conversion actions: type, attribution model, counting, metrics | — |
| `google_ads_get_audiences` | User lists incl. Customer Match (match rate, size, eligibility) | `list_type` |
| `google_ads_get_audience_performance` | Metrics per audience on campaigns and ad groups | `campaign_id` |
| `google_ads_get_negative_keywords` | Campaign- and ad-group-level negatives | `campaign_id` |
| `google_ads_get_negative_keyword_lists` | Shared negative lists, their keywords and attached campaigns | — |
| `google_ads_get_change_history` | Who changed what and when (last 30 days max) | `days`, `resource_type` |
| `google_ads_query` | Any [GAQL](https://developers.google.com/google-ads/api/docs/query/overview) query | `query` |

Requests go over the REST transport, so the gRPC extension isn't needed. The developer token needs **Standard Access** for production accounts; Basic Access works only with test accounts.

---

## Google Analytics 4

`property_id` is the numeric ID from Analytics → Admin → Property settings. Dates are relative (`today`, `7daysAgo`, `30daysAgo`) or `YYYY-MM-DD`; the default is the last 30 days.

| Tool | What it returns |
|---|---|
| `ga4_get_audience_overview` | Users, new users, sessions, engagement, bounce rate, duration — daily, new vs returning |
| `ga4_get_traffic_sources` | Sessions, conversions, revenue by channel group / source / medium |
| `ga4_get_top_pages` | Top pages by views with engagement and conversions |
| `ga4_get_conversions` | Key events by name and channel with revenue |
| `ga4_get_ecommerce` | Revenue, transactions, items, AOV |
| `ga4_get_geo_breakdown` | Traffic and conversions by country and city |
| `ga4_get_device_breakdown` | Traffic by device, OS, browser |
| `ga4_run_report` | Any dimensions × metrics ([explorer](https://ga-dev-tools.google/dimensions-metrics-explorer/)) |
| `ga4_run_realtime_report` | Active users right now |

---

## Meta Ads

`account_id` works with or without the `act_` prefix; start with `meta_get_ad_accounts`. Money values are in the account currency.

`date_preset`: `today` `yesterday` `last_3d` `last_7d` `last_14d` `last_28d` `last_30d` `last_90d` `this_month` `last_month` `this_quarter` `last_year` `lifetime`.

| Tool | What it returns | Key params |
|---|---|---|
| `meta_get_ad_accounts` | Accounts with status (1 active, 2 disabled, 3 unsettled, 7 pending review, 101 closed) | — |
| `meta_get_campaigns` | Campaigns with budget, objective, spend, ROAS, `actions` | `status` |
| `meta_get_adsets` | Ad sets with targeting and insights | `campaign_id` |
| `meta_get_ads` | Ads with creative reference and insights | `adset_id` |
| `meta_get_creatives` | Creative copy, images, CTA, destination URL | — |
| `meta_get_insights` | Insights with breakdowns (`age`, `gender`, `country`, `placement`, `publisher_platform`…), daily via `time_increment=1`, video metrics | `object_type`, `breakdowns` |
| `meta_get_attribution` | Conversions by attribution window: 1d/7d/28d click, 1d view | `object_type` |
| `meta_estimate_audience_size` | Potential reach of a targeting setup before launch | `countries`, `age_min`, `interests` |
| `meta_get_custom_audiences` | Custom and lookalike audiences with size | — |
| `meta_get_pixels` | Pixels and their last fire time | — |

Some breakdown combinations are incompatible; use one at a time when unsure.

---

## TikTok Ads

Start with `tiktok_get_advertisers` to get `advertiser_id`.

| Tool | What it returns | Key params |
|---|---|---|
| `tiktok_get_advertisers` | Advertiser accounts available to the token | — |
| `tiktok_get_campaigns` | Campaigns with status and budget | `status` |
| `tiktok_get_ad_groups` | Ad groups | `campaign_ids` |
| `tiktok_get_ads` | Ads with creative details | `adgroup_ids` |
| `tiktok_get_report` | Performance at campaign / ad group / ad level, optionally daily and with video metrics | `level`, `start_date`, `end_date`, `daily` |

---

## Google Search Console

Shares the Google OAuth token (scope `webmasters.readonly`). `site_url` is the property exactly as listed: `https://example.com/` or `sc-domain:example.com`.

| Tool | What it returns | Key params |
|---|---|---|
| `gsc_list_sites` | Properties and permission level | — |
| `gsc_search_analytics` | Clicks, impressions, CTR (%), position by `query` / `page` / `country` / `device` / `date` / `searchAppearance` | `dimensions`, `filters`, `search_type`, `row_limit` (≤ 25,000) |
| `gsc_list_sitemaps` | Sitemaps with errors, warnings and indexed counts | — |
| `gsc_inspect_url` | URL Inspection: index status, coverage, last crawl, canonical, rich results (~2,000/day per property) | `inspection_url` |

Data lags 2–3 days and goes back up to 16 months.

---

## Bing Webmaster Tools

Bing organic search, including Yahoo and DuckDuckGo syndication. The API returns the full history, so the stats tools filter by `start_date` / `end_date` and sort by impressions with `limit` (default 100, `0` = all).

| Tool | What it returns |
|---|---|
| `bing_list_sites` | Verified sites |
| `bing_get_traffic_stats` | Daily impressions and clicks |
| `bing_get_query_stats` | Top queries with clicks and average positions |
| `bing_get_page_stats` | Top pages with the same metrics |
| `bing_get_query_page_stats` | Pages ranking for one query |
| `bing_get_page_query_stats` | Queries that bring traffic to one page |
| `bing_get_crawl_stats` | Daily crawl: pages crawled, errors, in index, blocked by robots.txt, HTTP codes |
| `bing_get_crawl_issues` | URLs with crawl problems |
| `bing_get_link_counts` | Inbound links per page |
| `bing_get_url_info` | Index details for one URL |
| `bing_get_sitemaps` | Submitted sitemaps and their status |
| `bing_get_keyword_stats` | Keyword research: weekly Bing impressions for a query in a market |
| `bing_get_url_submission_quota` | Remaining URL submission quota |

---

## SerpBear

Rank tracking from a self-hosted [SerpBear](https://docs.serpbear.com). Keyword responses drop the raw SERP snapshot and keep 30 days of history by default (`include_serp_result`, `history_days` to change).

| Tool | What it does |
|---|---|
| `serpbear_get_domains` | Tracked domains with keyword counts |
| `serpbear_get_keywords` | Keywords of a domain: position, ranking URL, device, country, tags, history (`tag`, `device` filters) |
| `serpbear_get_keyword` | One keyword by ID |
| `serpbear_refresh_keywords` | Re-scrape positions now (spends scraper credits) |

---

## Microsoft Clarity

| Tool | What it returns |
|---|---|
| `clarity_get_insights` | Traffic, scroll depth, engagement time, rage / dead clicks, quickbacks, excessive scroll, JS errors; up to 3 breakdown dimensions (`Browser`, `Device`, `Country/Region`, `OS`, `Source`, `Medium`, `Campaign`, `Channel`, `URL`) |

API limits: only the **last 1–3 days**, **10 requests per project per day**, 1,000 rows, UTC.

---

## Rybbit

Analytics from a [Rybbit](https://rybbit.com) instance (self-hosted or cloud). Use an **organization API key**, which covers every site. A scoped key needs `read` on `analytics`, `sessions`, `events`, `users`, `goals`, `funnels`, `replay`, `segments`, `annotations`, `org`, plus `sql` for custom SQL.

Start with `rybbit_list_sites` to get `site_id` and `organization_id`. Most tools accept:

- a time range: `start_date` + `end_date` (`YYYY-MM-DD`, with `time_zone`), or `past_minutes_start` + `past_minutes_end`;
- `filters`: a JSON array such as `[{"parameter":"channel","type":"equals","value":["Paid Search"]}]`;
- `segment_id`.

| Area | Tools |
|---|---|
| Sites | `rybbit_list_sites` |
| Overview | `rybbit_get_overview`, `rybbit_get_overview_timeseries`, `rybbit_get_live_visitors` |
| Breakdowns | `rybbit_get_metric` (by channel, referrer, UTM, country, device, entry / exit page…), `rybbit_get_pages`, `rybbit_get_page_trends`, `rybbit_get_session_locations` |
| Sessions | `rybbit_get_sessions` (`view`: `replay`, `errors`, `converted`, `bounced`), `rybbit_get_session` |
| Session replay | `rybbit_list_session_replays`, `rybbit_get_session_replay` |
| Users | `rybbit_get_users`, `rybbit_get_user` (first-touch source, devices, Web Vitals), `rybbit_get_user_trait_keys` |
| Events | `rybbit_get_events`, `rybbit_get_event_names`, `rybbit_get_event_properties`, `rybbit_get_event_timeseries`, `rybbit_get_event_counts`, `rybbit_get_outbound_links` |
| Goals & funnels | `rybbit_get_goals`, `rybbit_get_goal_timeseries`, `rybbit_get_goal_sessions`, `rybbit_get_funnels`, `rybbit_analyze_funnel`, `rybbit_get_funnel_step_sessions` |
| Behaviour | `rybbit_get_journeys`, `rybbit_get_retention` |
| Performance & errors | `rybbit_get_performance`, `rybbit_get_performance_by_dimension`, `rybbit_get_errors`, `rybbit_get_error_events` |
| Bots & AI crawlers | `rybbit_get_bots_overview`, `rybbit_get_bots_by_dimension` (`purpose`: `ai_crawler`, `ai_search`, `ai_agent`…) |
| Segments & annotations | `rybbit_get_segments`, `rybbit_get_annotations`, `rybbit_create_annotation` (**write**) |
| Custom SQL | `rybbit_run_sql` — read-only ClickHouse over `scoped_events`, 1,000 rows, 60 s |

### Session replay analysis

A replay is thousands of raw rrweb events: mouse moves and DOM diffs. `rybbit_get_session_replay` turns them into something you can read:

- pages visited and the deepest scroll on each;
- each click or tap tied to the element under it (tag, visible text, `href`, `id`, `class`), resolved to the nearest button or link;
- **rage clicks**: 3 or more clicks within 1 s inside 40 px;
- form fields the visitor typed into. **Values are never returned**, and script-generated input (for example the Meta Pixel filling a hidden form) is filtered out;
- viewport changes and console errors;
- click IDs and Google Ads ValueTrack noise stripped from URLs; `utm_*` kept.

`include_raw: true` adds the first raw events for debugging.

---

## Errors

Tool errors come back in the MCP `content` array with `isError: true`.

| Error | Cause | Fix |
|---|---|---|
| Google `PERMISSION_DENIED` | The token can't see that account | `google_ads_list_customers` to see what's accessible |
| `Developer token is not approved` | Basic Access token | Apply for Standard Access at ads.google.com/aw/apicenter |
| `invalid_grant` / token expired | Google refresh token revoked | `auth_start_google` + `auth_finish_google` |
| Search Console *lacks the webmasters.readonly scope* | Token issued before GSC support | Re-run the Google OAuth flow once |
| Meta `(#100)` | Missing permission or bad parameter | Check `ads_read`, `ads_management`, `business_management` |
| Meta `Error validating access token` | Token older than ~60 days | `auth_start_meta` + `auth_finish_meta` |
| Rybbit `403` | Wrong `site_id`, or the key lacks scope or site access | `rybbit_list_sites`; otherwise create an organization key |
| Clarity `429` | 10 requests per day used up | Wait until tomorrow (UTC) |
| SerpBear `401` | Wrong `APIKEY` | `auth_set_serpbear` |
