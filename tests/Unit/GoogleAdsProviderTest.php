<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Replaces the Google Ads API with canned responses keyed by customer ID + FROM resource. */
final class FakeGoogleAdsProvider extends GoogleAdsProvider
{
    public array $queries = [];

    public function __construct(private array $accessible, private array $responses)
    {
        parent::__construct(['x' => '1']);
    }

    protected function accessibleCustomerIds(): array
    {
        return $this->accessible;
    }

    protected function searchStream(string $customerId, string $query): array
    {
        $this->queries[] = [$customerId, $query];
        preg_match('/FROM\s+(\w+)/i', $query, $m);
        $key = $customerId . ':' . strtolower($m[1] ?? '');
        $r = $this->responses[$key] ?? [];
        if ($r instanceof Throwable) {
            throw $r;
        }
        return $r;
    }
}

final class GoogleAdsProviderTest extends TestCase
{
    private const MANAGER_ERROR = '{"message":"Request contains an invalid argument.","details":[{"errors":[{"errorCode":{"queryError":"REQUESTED_METRICS_FOR_MANAGER"}}]}]}';

    private function provider(): FakeGoogleAdsProvider
    {
        return new FakeGoogleAdsProvider(['1230196680', '3548527947'], [
            '1230196680:customer' => [['customer' => ['id' => '1230196680', 'descriptiveName' => 'Joy', 'manager' => false, 'currencyCode' => 'UAH', 'timeZone' => 'Europe/Kyiv', 'status' => 'ENABLED']]],
            '3548527947:customer' => [['customer' => ['id' => '3548527947', 'descriptiveName' => 'Agency MCC', 'manager' => true, 'currencyCode' => 'USD']]],
            '3548527947:customer_client' => [
                ['customerClient' => ['id' => '1230196680', 'descriptiveName' => 'Joy', 'manager' => false, 'status' => 'ENABLED', 'currencyCode' => 'UAH']],
            ],
        ]);
    }

    public function testListCustomersMarksManagersAndListsClients(): void
    {
        $result = $this->provider()->listCustomers([]);

        $this->assertSame(2, $result['total']);
        [$client, $manager] = $result['customers'];

        $this->assertSame('Joy', $client['name']);
        $this->assertFalse($client['manager']);
        $this->assertArrayNotHasKey('client_accounts', $client);

        $this->assertTrue($manager['manager']);
        $this->assertSame('1230196680', $manager['client_accounts'][0]['customer_id']);
        $this->assertStringContainsString('client accounts', $manager['note']);
    }

    public function testListCustomersSurvivesPerAccountFailure(): void
    {
        $p = new FakeGoogleAdsProvider(['111'], ['111:customer' => new RuntimeException('USER_PERMISSION_DENIED')]);
        $result = $p->listCustomers([]);

        $this->assertSame('111', $result['customers'][0]['customer_id']);
        $this->assertStringContainsString('USER_PERMISSION_DENIED', $result['customers'][0]['details_error']);
    }

    /** Regression: metrics on an MCC returned a raw INVALID_ARGUMENT blob. */
    public function testMetricsOnManagerExplainWhichAccountsToUse(): void
    {
        $p = new FakeGoogleAdsProvider(['3548527947'], [
            '3548527947:customer' => new RuntimeException(self::MANAGER_ERROR),
            '3548527947:customer_client' => [
                ['customerClient' => ['id' => '1230196680', 'descriptiveName' => 'Joy', 'manager' => false]],
            ],
        ]);

        try {
            $p->getAccountMetrics(['customer_id' => '3548527947']);
            $this->fail('Expected an exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('manager (MCC)', $e->getMessage());
            $this->assertStringContainsString('1230196680', $e->getMessage());
            $this->assertStringNotContainsString('INVALID_ARGUMENT', $e->getMessage());
        }
    }

    public function testOtherApiErrorsPassThrough(): void
    {
        $p = new FakeGoogleAdsProvider([], ['1:customer' => new RuntimeException('QUOTA_EXCEEDED')]);

        $this->expectExceptionMessage('QUOTA_EXCEEDED');
        $p->getAccountMetrics(['customer_id' => '1']);
    }
}
