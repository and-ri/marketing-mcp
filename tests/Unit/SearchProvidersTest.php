<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FakeSearchConsole extends SearchConsoleProvider
{
    public array $calls = [];

    public function __construct(private array $response)
    {
        parent::__construct(['x' => '1']);
    }

    protected function request(string $method, string $url, ?array $body = null): array
    {
        $this->calls[] = compact('method', 'url', 'body');
        return $this->response;
    }
}

final class FakeBing extends BingWebmasterProvider
{
    public array $calls = [];

    public function __construct(private mixed $response)
    {
        parent::__construct(['x' => '1']);
    }

    protected function get(string $method, array $query = []): mixed
    {
        $this->calls[] = compact('method', 'query');
        return callStatic(BingWebmasterProvider::class, 'normalizeDates', $this->response);
    }
}

final class FakeSerpBear extends SerpBearProvider
{
    public array $calls = [];

    public function __construct(private array $response)
    {
        parent::__construct(['x' => '1']);
    }

    protected function request(string $method, string $path, array $query = []): array
    {
        $this->calls[] = compact('method', 'path', 'query');
        return $this->response;
    }
}

final class SearchProvidersTest extends TestCase
{
    // ─── Google Search Console ──────────────────────────────────────────────

    public function testGscMapsRowsAndBuildsRequest(): void
    {
        $p = new FakeSearchConsole(['rows' => [
            ['keys' => ['настільні ігри', 'https://joy.co.ua/'], 'clicks' => 12, 'impressions' => 340, 'ctr' => 0.0352941, 'position' => 4.567],
        ], 'responseAggregationType' => 'byPage']);

        $r = $p->searchAnalytics([
            'site_url'   => 'sc-domain:joy.co.ua',
            'start_date' => '2026-10-01',
            'end_date'   => '2026-10-31',
            'dimensions' => ['query', 'page'],
            'filters'    => [['dimension' => 'page', 'operator' => 'contains', 'expression' => '/nastilni']],
            'row_limit'  => 999999,
        ]);

        $row = $r['rows'][0];
        $this->assertSame('настільні ігри', $row['query']);
        $this->assertSame('https://joy.co.ua/', $row['page']);
        $this->assertSame(3.53, $row['ctr'], 'CTR is returned in percent');
        $this->assertSame(4.57, $row['position']);

        $call = $p->calls[0];
        $this->assertSame('POST', $call['method']);
        $this->assertStringContainsString(rawurlencode('sc-domain:joy.co.ua') . '/searchAnalytics/query', $call['url']);
        $this->assertSame(25000, $call['body']['rowLimit'], 'row_limit is capped at the API maximum');
        $this->assertSame('contains', $call['body']['dimensionFilterGroups'][0]['filters'][0]['operator']);
    }

    public function testGscRequiresSiteUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new FakeSearchConsole([]))->listSitemaps([]);
    }

    // ─── Bing Webmaster ─────────────────────────────────────────────────────

    public function testBingNormalizesWcfDatesAndDropsTypeHints(): void
    {
        $out = callStatic(BingWebmasterProvider::class, 'normalizeDates', [
            ['__type' => 'RankAndTrafficStats:#Microsoft.Bing.Webmaster.Api', 'Date' => '/Date(1762646400000-0800)/', 'Clicks' => 3],
            ['Date' => '/Date(1762732800000)/'],
        ]);

        $this->assertSame(['Date' => '2025-11-09', 'Clicks' => 3], $out[0]);
        $this->assertSame('2025-11-10', $out[1]['Date']);
    }

    public function testBingFiltersSortsAndLimitsStats(): void
    {
        $p = new FakeBing([
            ['Query' => 'a', 'Impressions' => 5, 'Date' => '/Date(1761955200000)/'],  // 2025-11-01
            ['Query' => 'b', 'Impressions' => 50, 'Date' => '/Date(1762560000000)/'], // 2025-11-08
            ['Query' => 'c', 'Impressions' => 20, 'Date' => '/Date(1762560000000)/'],
        ]);

        $r = $p->getQueryStats(['site_url' => 'https://joy.co.ua/', 'start_date' => '2025-11-05', 'limit' => 1]);

        $this->assertSame(2, $r['total'], 'rows before start_date are dropped');
        $this->assertSame(1, $r['returned']);
        $this->assertSame('b', $r['rows'][0]['Query'], 'sorted by impressions desc');
        $this->assertSame(['siteUrl' => 'https://joy.co.ua/'], $p->calls[0]['query']);
    }

    // ─── SerpBear ───────────────────────────────────────────────────────────

    public function testSerpBearDropsSerpDumpTrimsHistoryAndFilters(): void
    {
        $p = new FakeSerpBear(['keywords' => [
            ['ID' => 1, 'device' => 'desktop', 'tags' => ['brand'], 'lastResult' => [['position' => 1]],
             'history' => ['2026-11-03' => 5, '2026-11-01' => 7, '2026-11-02' => 6]],
            ['ID' => 2, 'device' => 'mobile', 'tags' => ['brand'], 'history' => []],
            ['ID' => 3, 'device' => 'desktop', 'tags' => [], 'history' => []],
        ]]);

        $r = $p->getKeywords(['domain' => 'joy.co.ua', 'tag' => 'brand', 'device' => 'desktop', 'history_days' => 2]);

        $this->assertSame(1, $r['total']);
        $kw = $r['keywords'][0];
        $this->assertArrayNotHasKey('lastResult', $kw);
        $this->assertSame(['2026-11-02' => 6, '2026-11-03' => 5], $kw['history']);
        $this->assertSame(['domain' => 'joy.co.ua'], $p->calls[0]['query']);
    }

    public function testSerpBearRefreshSendsCommaSeparatedIds(): void
    {
        $p = new FakeSerpBear(['keywords' => []]);
        $p->refreshKeywords(['ids' => [3, '7', 0, 12]]);

        $this->assertSame('POST', $p->calls[0]['method']);
        $this->assertSame(['id' => '3,7,12'], $p->calls[0]['query']);
    }
}
