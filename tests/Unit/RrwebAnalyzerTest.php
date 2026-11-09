<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RrwebAnalyzerTest extends TestCase
{
    private const T = 1700000000000;

    private static function snapshot(): array
    {
        $body = [
            ['type' => 2, 'id' => 4, 'tagName' => 'button', 'attributes' => ['class' => 'btn buy', 'type' => 'button'], 'childNodes' => [
                ['type' => 2, 'id' => 5, 'tagName' => 'span', 'attributes' => [], 'childNodes' => [
                    ['type' => 3, 'id' => 6, 'textContent' => '  Купити  '],
                ]],
            ]],
            ['type' => 2, 'id' => 7, 'tagName' => 'input', 'attributes' => ['name' => 'email', 'type' => 'email', 'value' => 'secret@example.com'], 'childNodes' => []],
            ['type' => 2, 'id' => 8, 'tagName' => 'a', 'attributes' => ['href' => '/cart'], 'childNodes' => [
                ['type' => 3, 'id' => 9, 'textContent' => 'Кошик'],
            ]],
            ['type' => 2, 'id' => 10, 'tagName' => 'input', 'attributes' => ['name' => 'csrf', 'type' => 'hidden'], 'childNodes' => []],
        ];
        return ['type' => 0, 'id' => 1, 'childNodes' => [
            ['type' => 2, 'id' => 2, 'tagName' => 'html', 'attributes' => [], 'childNodes' => [
                ['type' => 2, 'id' => 3, 'tagName' => 'body', 'attributes' => [], 'childNodes' => $body],
            ]],
        ]];
    }

    private static function ev(int $ms, int $type, array $data): array
    {
        return ['type' => $type, 'timestamp' => self::T + $ms, 'data' => $data];
    }

    private static function click(int $ms, int $id, int $x, int $y): array
    {
        return self::ev($ms, 3, ['source' => 2, 'type' => 2, 'id' => $id, 'x' => $x, 'y' => $y]);
    }

    private static function input(int $ms, int $id): array
    {
        return self::ev($ms, 3, ['source' => 5, 'id' => $id, 'text' => 'secret@example.com']);
    }

    private static function session(): array
    {
        $events = [
            self::ev(0, 4, ['href' => 'https://joy.co.ua/p/1?utm_source=google&gclid=XYZ', 'width' => 390, 'height' => 844]),
            self::ev(10, 2, ['node' => self::snapshot(), 'initialOffset' => ['top' => 0, 'left' => 0]]),
            self::ev(500, 3, ['source' => 1, 'positions' => []]),
            self::ev(2000, 3, ['source' => 3, 'id' => 1, 'x' => 0, 'y' => 1200]),
            self::click(3000, 6, 100, 200),  // text node inside the button
            self::click(3200, 5, 105, 202),
            self::click(3400, 5, 102, 199),
            self::input(5000, 7),
            self::input(5300, 7),
            self::input(5600, 10),           // hidden field: ignored
            self::ev(8000, 3, ['source' => 11, 'level' => 'error', 'payload' => ['TypeError: x is undefined']]),
            self::click(9000, 9, 50, 600),
            self::ev(9500, 4, ['href' => 'https://joy.co.ua/cart', 'width' => 390, 'height' => 844]),
        ];
        // Meta Pixel: hidden form with many fields added and filled programmatically at once
        $adds = [];
        for ($i = 0; $i < 30; $i++) {
            $adds[] = ['parentId' => 3, 'node' => ['type' => 2, 'id' => 100 + $i, 'tagName' => 'input', 'attributes' => ['name' => "f$i"], 'childNodes' => []]];
        }
        $events[] = self::ev(1500, 3, ['source' => 0, 'adds' => $adds]);
        for ($i = 0; $i < 30; $i++) {
            $events[] = self::ev(1600, 3, ['source' => 5, 'id' => 100 + $i, 'text' => 'x', 'isChecked' => false]);
        }
        shuffle($events); // the analyzer must sort by timestamp itself
        return $events;
    }

    public function testBuildsPagesWithScrollDepthAndCleanUrls(): void
    {
        $r = RrwebAnalyzer::analyze(self::session(), 100);

        $this->assertSame(9.5, $r['duration_s']);
        $this->assertSame([
            ['url' => 'https://joy.co.ua/p/1?utm_source=google', 'first_at_s' => 0.0, 'max_scroll_y_px' => 1200],
            ['url' => 'https://joy.co.ua/cart', 'first_at_s' => 9.5, 'max_scroll_y_px' => 0],
        ], $r['pages']);
    }

    public function testResolvesClicksToInteractiveElements(): void
    {
        $r = RrwebAnalyzer::analyze(self::session(), 100);
        $clicks = array_values(array_filter($r['timeline'], fn($e) => $e['event'] === 'click'));

        $this->assertCount(4, $clicks);
        $button = ['tag' => 'button', 'text' => 'Купити', 'class' => 'btn buy', 'type' => 'button'];
        $this->assertSame($button, $clicks[0]['element'], 'click on a text node resolves to the parent button');
        $this->assertSame($button, $clicks[1]['element'], 'click on a <span> climbs to the button');
        $this->assertSame(['tag' => 'a', 'text' => 'Кошик', 'href' => '/cart'], $clicks[3]['element']);
    }

    public function testDetectsRageClicks(): void
    {
        $r = RrwebAnalyzer::analyze(self::session(), 100);

        $this->assertCount(1, $r['rage_clicks']);
        $this->assertSame(3, $r['rage_clicks'][0]['clicks']);
        $this->assertSame('button', $r['rage_clicks'][0]['element']['tag']);
    }

    public function testIgnoresProgrammaticAndHiddenInputsAndMergesKeystrokes(): void
    {
        $r = RrwebAnalyzer::analyze(self::session(), 100);

        $this->assertSame([['tag' => 'input', 'name' => 'email', 'type' => 'email']], $r['fields_interacted']);
        $this->assertSame(30, $r['activity_counts']['programmatic_inputs_ignored']);
        $inputs = array_filter($r['timeline'], fn($e) => $e['event'] === 'input');
        $this->assertCount(1, $inputs, 'consecutive keystrokes collapse into one entry');
    }

    public function testNeverLeaksInputValues(): void
    {
        $json = json_encode(RrwebAnalyzer::analyze(self::session(), 100), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('secret@example.com', $json);
    }

    public function testCapturesConsoleErrorsAndTruncates(): void
    {
        $r = RrwebAnalyzer::analyze(self::session(), 100);
        $errors = array_values(array_filter($r['timeline'], fn($e) => $e['event'] === 'console_error'));
        $this->assertSame('TypeError: x is undefined', $errors[0]['message']);

        $short = RrwebAnalyzer::analyze(self::session(), 2);
        $this->assertCount(2, $short['timeline']);
        $this->assertTrue($short['timeline_truncated']);
    }

    public function testEmptyReplay(): void
    {
        $this->assertSame(['note' => 'Replay has no events.'], RrwebAnalyzer::analyze([], 10));
    }
}
