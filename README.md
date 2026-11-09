# marketing-mcp

**Your whole marketing stack, available to Claude.** One self-hosted [MCP](https://modelcontextprotocol.io) server connects Claude to your ad accounts, analytics, search consoles and session replays, so you can ask questions in plain language instead of exporting CSVs from nine dashboards.

```text
You:     Why did Google Ads conversions on example.com drop last week?

Claude:  Spend held steady but conversions fell, and almost all of the drop is
         on mobile. Rybbit shows bounce on /checkout rising since Tuesday.
         Replays of paid sessions show rage clicks on the "Place order" button,
         followed by a console error: "TypeError: payment is undefined"…
```

<sub>Illustrative example.</sub>

113 tools · 9 platforms · multi-user · OAuth from the chat · read-only by design

---

## Platforms

| | Platform | Tools | What Claude can see |
|---|---|---:|---|
| 📣 | **Google Ads** | 17 | Campaigns, keywords with QS, search terms, RSA/PMax asset labels, audiences, negatives, change history, any GAQL |
| 📣 | **Meta Ads** | 10 | Campaigns → ads, creatives, breakdowns, attribution windows, audience size estimates, pixels |
| 📣 | **TikTok Ads** | 5 | Advertisers, campaigns, ad groups, ads, performance reports |
| 📊 | **Google Analytics 4** | 9 | Traffic sources, top pages, conversions, e-commerce, geo/device, custom and realtime reports |
| 🔍 | **Google Search Console** | 4 | Queries and pages with CTR and position, sitemaps, URL inspection |
| 🔍 | **Bing Webmaster Tools** | 13 | Bing queries and pages, crawl stats and issues, backlinks, keyword research |
| 🔍 | **SerpBear** | 4 | Daily keyword positions from your self-hosted rank tracker |
| 🖱️ | **Microsoft Clarity** | 1 | Rage/dead clicks, quickbacks, scroll depth, JS errors |
| 🖱️ | **Rybbit** | 39 | Sessions, **session replays**, funnels, journeys, retention, Web Vitals, errors, **AI crawlers**, custom SQL |

Full list with parameters: **[docs/TOOLS.md](docs/TOOLS.md)**.

## What to ask

- *"Which search terms spent over ₴500 last month without a single conversion? Draft a negative keyword list."*
- *"Compare ROAS across Google Ads, Meta and TikTok for October and tell me where to move budget."*
- *"Which pages lost Google clicks this month, and did their SerpBear positions drop too?"*
- *"Find funnel drop-offs between cart and checkout and watch five of those session replays. What do people struggle with?"*
- *"Which AI crawlers (GPTBot, ClaudeBot, PerplexityBot) read my site, and which pages do they read?"*
- *"Which RSA headlines are rated LOW? Suggest replacements in the tone of the BEST ones."*
- *"Are there queries where we rank well on Bing but badly on Google?"*

## Highlights

- **Session replays Claude can actually read.** A raw replay is thousands of rrweb events. `rybbit_get_session_replay` turns one into a timeline: pages, scroll depth, clicks resolved to the clicked button or link, rage clicks, form fields touched, console errors. Typed values are never returned.
- **One Google sign-in** covers Ads, GA4 and Search Console.
- **Authorize from the chat.** Ask Claude to *"connect Google Ads"*. It reads the setup guide built into the tool, gives you the OAuth link, and saves the token. You never need a shell on the server.
- **Multi-user.** Every user gets their own token and their own stored credentials.
- **Read-only.** Nothing touches your campaigns, budgets or bids. There are two exceptions, and both run only when you ask: `rybbit_create_annotation` adds a note to the Rybbit timeline, and `serpbear_refresh_keywords` triggers a rank re-check.
- **Lightweight.** PHP 8.5 + ReactPHP, a single container, SQLite. Google APIs go over REST, so there are no native extensions to compile.

---

## Quick start (Docker)

### 1. Run the server

```bash
git clone https://github.com/and-ri/marketing-mcp.git
cd marketing-mcp
docker compose up -d --build
```

Configure the stack with these environment variables, either in a `.env` file next to `docker-compose.yml` or under **Environment variables** in Portainer. Platform credentials don't go here; each user adds their own from the chat (step 4).

| Variable | Example | Purpose |
|---|---|---|
| `MCP_BASE_URL` | `https://mcp.example.com` | Public URL. Used for OAuth callbacks |
| `MCP_DATA_PATH` | `/opt/marketing-mcp/data` | Host directory for `users.sqlite`. If unset, a named volume is used |
| `DEBUG` | `false` | `true` logs requests to `/app/data/debug.log` |

> ⚠️ Keep `MCP_DATA_PATH` the same across redeploys. That directory holds all users and their credentials.

The image is built on your host from source; only the official `php` and `composer` images are pulled. No port is published. The container joins the external Docker network `proxy` as **`marketing-mcp:8080`**:

```bash
docker network create proxy   # once, then attach your reverse proxy to it
```

In Nginx Proxy Manager, add a Proxy Host for your domain → scheme `http`, host `marketing-mcp`, port `8080` → enable SSL. Check it with `curl https://mcp.example.com/health`, which should return `OK`.

### 2. Create a user

```bash
docker compose exec mcp php users.php add alice
```

This prints a **Bearer token**, which is that user's key to the server.

### 3. Connect Claude

**claude.ai / Claude Desktop:** Settings → Connectors → *Add custom connector* → URL `https://mcp.example.com/mcp`. Claude opens a login page; paste the token from step 2.

**Claude Code:** add this to `~/.claude.json` or the project's `.mcp.json`:

```json
{
  "mcpServers": {
    "marketing": {
      "type": "http",
      "url": "https://mcp.example.com/mcp",
      "headers": { "Authorization": "Bearer <token>" }
    }
  }
}
```

### 4. Connect your platforms

Ask Claude: *"Run auth_status and help me connect what's missing."*

| Platform | How | What you need |
|---|---|---|
| Google Ads + GA4 + Search Console | OAuth: `auth_start_google` → browser → `auth_finish_google` | Google Cloud OAuth client (*Web application*) and an Ads developer token. Enable the Google Ads, Analytics Data and Search Console APIs |
| Meta Ads | OAuth: `auth_start_meta` → `auth_finish_meta` | Meta app with Marketing API + Facebook Login. Token lasts ~60 days |
| TikTok Ads | OAuth: `auth_start_tiktok` → `auth_finish_tiktok` | TikTok for Business app with Marketing API |
| Microsoft Clarity | `auth_set_clarity` | Settings → Data Export → API token |
| Rybbit | `auth_set_rybbit` | Instance URL + **organization** API key |
| Bing Webmaster | `auth_set_bing_webmaster` | Settings → API access → API Key |
| SerpBear | `auth_set_serpbear` | Instance URL + `APIKEY` from its `.env` |

Register `https://<your-domain>/oauth/callback` as the redirect URI in the Google, Meta and TikTok apps. Each `auth_start_*` tool includes a step-by-step setup guide that Claude follows with you.

---

## Operations

```bash
git pull && docker compose up -d --build                 # update
docker compose logs -f mcp                               # logs
docker compose exec mcp php users.php list               # users and tokens
docker compose exec mcp php users.php env:show alice     # stored keys (masked)
docker compose exec mcp php users.php env:set alice KEY value
docker compose exec -T mcp php users.php env:import alice < creds.env
docker compose exec mcp php users.php token:reset alice  # new token if leaked
docker compose exec mcp php users.php remove alice
```

Stored keys use the same names as [`.env.example`](.env.example).

After an update that adds tools, reconnect the server in your client (for example `/mcp` in Claude Code) so the new tools appear.

## Local mode (stdio, single user)

No Docker and no HTTP. Credentials live in a local `.env`.

```bash
composer install
php auth.php        # interactive OAuth for Google and Meta → writes .env
```

For the other platforms, fill in their keys in `.env` by hand (see [`.env.example`](.env.example)). Then point your MCP client at the server:

```json
{
  "mcpServers": {
    "marketing": { "command": "php", "args": ["/path/to/marketing-mcp/app.php"] }
  }
}
```

Every platform tool is available in local mode. The `auth_*` tools exist only in HTTP mode.

---

## Tests

```bash
composer install && composer test     # local PHP
docker build --target test .          # same suite inside the production runtime
```

Unit tests cover the providers against faked APIs, the replay analyzer, tool schemas and the entry points. The integration test boots `server.php` on a free port and speaks MCP to it over HTTP. Run the Docker target after changing the Dockerfile or dependencies; it catches missing PHP extensions that your local PHP may already have.

## Project layout

```text
server.php              HTTP server (ReactPHP): MCP endpoint, OAuth for connectors and platforms
app.php                 stdio entry point
users.php               user management CLI
src/McpServer.php       JSON-RPC / MCP protocol
src/Providers/*.php     one provider per platform; each registers its tools
docs/TOOLS.md           tools reference
tests/                  PHPUnit: Unit/ (faked APIs) and Integration/ (live HTTP server)
```

To add a platform, write a provider with `registerTools(McpServer $server)`, then register it in `server.php` and `app.php`.
