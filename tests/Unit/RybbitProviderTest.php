<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FakeRybbit extends RybbitProvider
{
    public array $calls = [];

    /** @param array<string, array> $responses keyed by "METHOD path" */
    public function __construct(private array $responses)
    {
        parent::__construct(['x' => '1']);
    }

    protected function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $this->calls[] = compact('method', 'path', 'query', 'body');
        return $this->responses["$method $path"] ?? [];
    }
}

final class RybbitProviderTest extends TestCase
{
    public function testListSitesFlattensOrganizations(): void
    {
        $p = new FakeRybbit(['GET /api/organizations' => [[
            'id' => 'org1', 'name' => 'And Net', 'role' => 'owner',
            'sites' => [['id' => '7', 'domain' => 'joy.co.ua', 'name' => 'joy.co.ua', 'public' => false]],
        ]]]);

        $r = $p->listSites([]);

        $this->assertSame('org1', $r['organizations'][0]['organization_id']);
        $this->assertSame(['site_id' => '7', 'domain' => 'joy.co.ua', 'name' => 'joy.co.ua', 'public' => false], $r['organizations'][0]['sites'][0]);
    }

    /** Regression: these endpoints moved; the old paths returned 404. */
    public function testUsesCurrentApiPaths(): void
    {
        $p = new FakeRybbit([]);
        $p->getOverviewTimeseries(['site_id' => '7']);
        $p->getErrors(['site_id' => '7']);

        $this->assertSame('/api/sites/7/overview/time-series', $p->calls[0]['path']);
        $this->assertSame('/api/sites/7/errors/names', $p->calls[1]['path']);
    }

    public function testCommonParamsEncodeFiltersAndSkipEmpty(): void
    {
        $p = new FakeRybbit([]);
        $p->getMetric([
            'site_id'    => '7',
            'parameter'  => 'channel',
            'start_date' => '2026-11-01',
            'end_date'   => '2026-11-09',
            'time_zone'  => '',
            'filters'    => [['parameter' => 'device_type', 'type' => 'equals', 'value' => ['Mobile']]],
        ]);

        $q = $p->calls[0]['query'];
        $this->assertSame('channel', $q['parameter']);
        $this->assertArrayNotHasKey('time_zone', $q);
        $this->assertSame([['parameter' => 'device_type', 'type' => 'equals', 'value' => ['Mobile']]], json_decode($q['filters'], true));
    }

    public function testSiteIdIsRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new FakeRybbit([]))->getOverview([]);
    }

    public function testFunnelNeedsTwoStepsAndPostsBody(): void
    {
        $p = new FakeRybbit([]);
        $steps = [['type' => 'page', 'value' => '/cart'], ['type' => 'page', 'value' => '/checkout']];
        $p->getFunnelStepSessions(['site_id' => '7', 'steps' => $steps, 'step_number' => 2, 'mode' => 'dropped', 'replays_only' => true]);

        $call = $p->calls[0];
        $this->assertSame('POST', $call['method']);
        $this->assertSame('/api/sites/7/funnels/2/sessions', $call['path']);
        $this->assertSame('true', $call['query']['replays_only']);
        $this->assertSame(['steps' => $steps], $call['body']);

        $this->expectException(InvalidArgumentException::class);
        $p->analyzeFunnel(['site_id' => '7', 'steps' => [['type' => 'page', 'value' => '/']]]);
    }

    public function testReplayListCleansTrackingParams(): void
    {
        $p = new FakeRybbit(['GET /api/sites/7/session-replay/list' => ['data' => [
            ['session_id' => 's1', 'page_url' => 'https://joy.co.ua/p?utm_source=google&gclid=abc&gbraid=x&creative=&color=red'],
        ]]]);

        $r = $p->listSessionReplays(['site_id' => '7']);

        $this->assertSame('https://joy.co.ua/p?utm_source=google&color=red', $r['data'][0]['page_url']);
    }

    public function testCleanUrlKeepsPlainUrls(): void
    {
        $this->assertSame('https://joy.co.ua/', RrwebAnalyzer::cleanUrl('https://joy.co.ua/'));
        $this->assertSame('https://joy.co.ua/a', RrwebAnalyzer::cleanUrl('https://joy.co.ua/a?srsltid=AYCX9&gad_source=1'));
    }
}
