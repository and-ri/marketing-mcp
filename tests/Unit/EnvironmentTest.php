<?php

declare(strict_types=1);

use Google\Ads\GoogleAds\V24\Services\GoogleAdsRow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guards the runtime: run the suite inside the Docker image to catch a missing extension
 * (e.g. bcmath → "Call to undefined function Google\Protobuf\Internal\bccomp()").
 */
final class EnvironmentTest extends TestCase
{
    public static function requiredExtensions(): array
    {
        $composer = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);
        $exts = [];
        foreach (array_keys($composer['require']) as $req) {
            if (str_starts_with($req, 'ext-')) {
                $exts[$req] = [substr($req, 4)];
            }
        }
        return $exts;
    }

    #[DataProvider('requiredExtensions')]
    public function testRequiredExtensionIsLoaded(string $ext): void
    {
        $this->assertTrue(extension_loaded($ext), "PHP extension '$ext' is required (see composer.json)");
    }

    public function testComposerDeclaresBcmath(): void
    {
        $this->assertArrayHasKey('bcmath', array_flip(array_merge(...array_values(self::requiredExtensions()))));
    }

    /** Exactly what the REST transport does with every Google Ads row that carries int64 metrics. */
    public function testProtobufDecodesInt64FromJson(): void
    {
        $row = new GoogleAdsRow();
        $row->mergeFromJsonString('{"metrics":{"impressions":"9007199254740993","costMicros":"123456789012"},"customer":{"id":"3548527947"}}');

        $this->assertSame(123456789012, (int) $row->getMetrics()->getCostMicros());
        $this->assertSame(3548527947, (int) $row->getCustomer()->getId());

        $json = json_decode($row->serializeToJsonString(), true);
        $this->assertSame('123456789012', $json['metrics']['costMicros']);
    }
}
