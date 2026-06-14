<?php

class ClarityProvider
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config ?: $_ENV;
    }

    private function token(): string
    {
        $token = $this->config['CLARITY_API_TOKEN'] ?? '';
        if ($token === '') {
            throw new \RuntimeException(
                'CLARITY_API_TOKEN is not configured. Generate it in Clarity → Settings → Data Export → Generate new API token.'
            );
        }
        return $token;
    }

    private function request(array $query): array
    {
        $url = 'https://www.clarity.ms/export-data/api/v1/project-live-insights?' . http_build_query($query);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->token(),
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException("Clarity API request failed: $err");
        }
        if ($status === 429) {
            throw new \RuntimeException('Clarity API daily request limit exceeded (max 10 requests per project per day).');
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("Clarity API returned HTTP $status: $body");
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new \RuntimeException("Clarity API returned invalid JSON: $body");
        }
        return $data;
    }

    public function registerTools(McpServer $server): void
    {
        $server->registerTool(
            'clarity_get_insights',
            'Get Microsoft Clarity behavioral analytics (live insights) for your project: traffic, scroll depth, engagement time, plus UX-friction signals like rage clicks, dead clicks, quickbacks, excessive scrolling, and JavaScript errors. Complements GA4 by showing WHY users struggle on a page. Data is limited to the last 1–3 days; max 10 API calls per project per day; response capped at 1000 rows.',
            [
                'type'       => 'object',
                'properties' => [
                    'num_of_days' => ['type' => 'integer', 'description' => 'Number of days to fetch: 1, 2, or 3 (last 24/48/72 hours). Capped automatically.', 'default' => 1],
                    'dimension1'  => ['type' => 'string', 'description' => 'Optional breakdown dimension: Browser, Device, Country/Region, OS, Source, Medium, Campaign, Channel, or URL'],
                    'dimension2'  => ['type' => 'string', 'description' => 'Optional second breakdown dimension'],
                    'dimension3'  => ['type' => 'string', 'description' => 'Optional third breakdown dimension'],
                ],
                'required' => [],
            ],
            [$this, 'getInsights']
        );
    }

    public function getInsights(array $args): array
    {
        $numOfDays = min(max((int) ($args['num_of_days'] ?? 1), 1), 3);
        $query = ['numOfDays' => $numOfDays];
        foreach (['dimension1', 'dimension2', 'dimension3'] as $dim) {
            if (!empty($args[$dim])) {
                $query[$dim] = (string) $args[$dim];
            }
        }

        $data = $this->request($query);
        return ['num_of_days' => $numOfDays, 'metrics' => $data];
    }
}
