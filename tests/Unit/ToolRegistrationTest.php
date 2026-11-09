<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ToolRegistrationTest extends TestCase
{
    private const PLATFORM_PROVIDERS = [
        GoogleAdsProvider::class,
        GoogleAnalyticsProvider::class,
        MetaAdsProvider::class,
        TikTokAdsProvider::class,
        ClarityProvider::class,
        RybbitProvider::class,
        SearchConsoleProvider::class,
        BingWebmasterProvider::class,
        SerpBearProvider::class,
    ];

    private static function allTools(): array
    {
        $providers = array_map(fn($c) => new $c(['x' => '1']), self::PLATFORM_PROVIDERS);
        $providers[] = new AuthProvider([], new PDO('sqlite::memory:'), 1, 'https://mcp.test');
        return registeredTools(...$providers);
    }

    public function testToolCount(): void
    {
        $this->assertCount(113, self::allTools(), 'Update README/docs when the tool count changes');
    }

    public function testEveryToolHasAValidSchema(): void
    {
        foreach (self::allTools() as $name => $tool) {
            $this->assertMatchesRegularExpression('/^[a-z0-9_]{1,64}$/', $name);
            $this->assertNotSame('', trim($tool['description']), "$name: empty description");

            $schema = $tool['inputSchema'];
            $this->assertSame('object', $schema['type'] ?? null, "$name: schema type must be object");
            $props = (array) ($schema['properties'] ?? []);
            foreach ($schema['required'] ?? [] as $req) {
                $this->assertArrayHasKey($req, $props, "$name: required '$req' is not a declared property");
            }
            foreach ($props as $prop => $def) {
                $this->assertIsArray($def, "$name.$prop: property definition must be an object");
                $this->assertArrayHasKey('type', $def, "$name.$prop: missing type");
                if (($def['type'] ?? null) === 'array') {
                    $this->assertArrayHasKey('items', $def, "$name.$prop: array without items");
                }
            }
            // The schema must survive JSON encoding as an object, not a list
            $this->assertStringStartsWith('{', json_encode($schema['properties']), "$name: properties must encode as a JSON object");
        }
    }

    public function testDocsListEveryTool(): void
    {
        $docs = file_get_contents(__DIR__ . '/../../docs/TOOLS.md');
        foreach (array_keys(self::allTools()) as $name) {
            $this->assertStringContainsString($name, $docs, "$name is missing from docs/TOOLS.md");
        }
    }

    /** Regression: app.php (stdio) used to register only 3 of the 9 platforms. */
    public function testEntrypointsRegisterAllProviders(): void
    {
        $find = function (string $file): array {
            preg_match_all('/new\s+(\w+Provider)\s*\(/', file_get_contents(__DIR__ . "/../../$file"), $m);
            return array_values(array_unique($m[1]));
        };

        $expected = array_map(fn($c) => $c, self::PLATFORM_PROVIDERS);
        sort($expected);

        $stdio = $find('app.php');
        sort($stdio);
        $this->assertSame($expected, $stdio, 'app.php must register every platform provider');

        $http = array_values(array_diff($find('server.php'), ['AuthProvider']));
        sort($http);
        $this->assertSame($expected, $http, 'server.php must register every platform provider');
    }
}
