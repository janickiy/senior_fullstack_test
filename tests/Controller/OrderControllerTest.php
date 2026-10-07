<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class OrderControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $shopId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->shopId = 'test-http-'.bin2hex(random_bytes(8));
    }

    public function testFixtureImportReportsEveryOutcomeAndPersistsCalculatedOrders(): void
    {
        $response = $this->import($this->fixture());

        self::assertSame([
            'total' => 11,
            'created' => 7,
            'updated' => 0,
            'unchanged' => 0,
            'duplicate' => 1,
            'rejected' => 3,
        ], $response['summary']);
        self::assertCount(11, $response['results']);

        foreach ($response['results'] as $index => $result) {
            self::assertSame($index, $result['index']);
            self::assertSame($this->fixture()['orders'][$index]['id'], $result['marketplace_id']);
            self::assertNotEmpty($result['message']);
        }

        self::assertSame('rejected', $response['results'][5]['outcome']);
        self::assertSame('unknown_district', $response['results'][5]['code']);
        self::assertSame('rejected', $response['results'][6]['outcome']);
        self::assertSame('invalid_phone', $response['results'][6]['code']);
        self::assertSame('rejected', $response['results'][7]['outcome']);
        self::assertSame('unknown_status', $response['results'][7]['code']);
        self::assertSame('duplicate', $response['results'][9]['outcome']);
        self::assertNotEmpty($response['results'][8]['warnings']);

        $orders = $this->ordersByMarketplaceId($this->list()['items']);
        self::assertCount(7, $orders);
        $expected = [
            'MP-1001' => ['new', '+79001234567', 3200, 300, 3500, false],
            'MP-1002' => ['accepted', '+79001112233', 5000, 600, 5600, false],
            'MP-1003' => ['new', '+79005557788', 6400, 0, 6400, false],
            'MP-1004' => ['new', '+79004443322', 1500, 0, 1500, false],
            'MP-1005' => ['delivered', '+79001230000', 2800, 400, 3200, false],
            'MP-1009' => ['new', '+79003334455', 3600, 700, 4300, true],
            'MP-1010' => ['cancelled', '+79004445566', 2000, 300, 2300, false],
        ];

        foreach ($expected as $marketplaceId => [$status, $phone, $itemsTotal, $deliveryCost, $total, $needsReview]) {
            self::assertArrayHasKey($marketplaceId, $orders);
            $order = $orders[$marketplaceId];
            self::assertSame($status, $order['status']);
            self::assertSame($phone, $order['customer']['phone']);
            self::assertEquals($itemsTotal, $order['items_total']);
            self::assertEquals($deliveryCost, $order['delivery_cost']);
            self::assertEquals($total, $order['total']);
            self::assertSame($needsReview, $order['needs_review']);
        }

        self::assertEquals(3000, $orders['MP-1009']['marketplace_total']);
        self::assertSame('2026-10-10T14:00:00+03:00', $orders['MP-1001']['delivery']['starts_at']);
        self::assertSame('2026-10-10T16:00:00+03:00', $orders['MP-1001']['delivery']['ends_at']);
        self::assertSame('pickup', $orders['MP-1004']['delivery']['type']);
        self::assertSame('Букет «Утро»', $orders['MP-1004']['items'][0]['name']);
    }

    public function testRepeatingTheSameBatchIsIdempotent(): void
    {
        $this->import($this->fixture());
        $before = $this->ordersByMarketplaceId($this->list()['items']);

        $response = $this->import($this->fixture());

        self::assertSame([
            'total' => 11,
            'created' => 0,
            'updated' => 0,
            'unchanged' => 7,
            'duplicate' => 1,
            'rejected' => 3,
        ], $response['summary']);
        self::assertSame($before, $this->ordersByMarketplaceId($this->list()['items']));
    }

    public function testRepeatedImportUpdatesOnlyStatus(): void
    {
        $original = $this->fixture()['orders'][0];
        $this->import(['orders' => [$original]]);
        $before = $this->list()['items'][0];

        $changed = $original;
        $changed['status'] = 'IN_DELIVERY';
        $changed['created_at'] = '2026-10-08T11:00:00+03:00';
        $changed['customer'] = ['name' => 'Другой покупатель', 'phone' => '89000000001'];
        $changed['delivery'] = [
            'type' => 'delivery',
            'district' => 'suburb',
            'address' => 'Другой адрес, 9',
            'date' => '2026-10-15',
            'time_from' => '11:00',
            'time_to' => '13:00',
        ];
        $changed['items'] = [['sku' => 'OTHER', 'name' => 'Другой букет', 'qty' => 3, 'price' => 100]];
        $changed['total'] = 200;

        $response = $this->import(['orders' => [$changed]]);

        self::assertSame(1, $response['summary']['updated']);
        self::assertSame('updated', $response['results'][0]['outcome']);
        $before['status'] = 'delivering';
        self::assertSame($before, $this->list()['items'][0]);

        $repeated = $this->import(['orders' => [$changed]]);
        self::assertSame(1, $repeated['summary']['unchanged']);
    }

    public function testTerminalStatusesCannotBeChangedByLaterImports(): void
    {
        $delivered = $this->fixture()['orders'][4];
        $cancelled = $this->fixture()['orders'][10];
        $this->import(['orders' => [$delivered, $cancelled]]);
        $before = $this->ordersByMarketplaceId($this->list()['items']);

        foreach ([['NEW', 'ACCEPTED'], ['CANCELED', 'DONE']] as [$deliveredStatus, $cancelledStatus]) {
            $delivered['status'] = $deliveredStatus;
            $cancelled['status'] = $cancelledStatus;

            $response = $this->import(['orders' => [$delivered, $cancelled]]);

            self::assertSame(2, $response['summary']['unchanged']);
            self::assertSame(0, $response['summary']['updated']);
            self::assertSame($before, $this->ordersByMarketplaceId($this->list()['items']));
        }
    }

    public function testShopListsAndImportIdentityAreIsolated(): void
    {
        $otherShopId = $this->shopId.'-other';
        $first = $this->fixture()['orders'][0];
        $this->import(['orders' => [$first]]);

        self::assertSame([], $this->list([], $otherShopId)['items']);

        $other = $first;
        $other['status'] = 'ACCEPTED';
        $other['customer']['name'] = 'Покупатель второго магазина';
        $response = $this->import(['orders' => [$other]], $otherShopId);

        self::assertSame(1, $response['summary']['created']);
        $firstShopOrder = $this->list()['items'][0];
        $otherShopOrder = $this->list([], $otherShopId)['items'][0];
        self::assertSame('new', $firstShopOrder['status']);
        self::assertSame('accepted', $otherShopOrder['status']);
        self::assertSame('Анна Лебедева', $firstShopOrder['customer']['name']);
        self::assertSame('Покупатель второго магазина', $otherShopOrder['customer']['name']);
        self::assertNotSame($firstShopOrder['id'], $otherShopOrder['id']);
    }

    public function testStatusFilteringAndPagination(): void
    {
        $this->import($this->fixture());
        $filtered = $this->list(['status' => 'new', 'page' => 1, 'limit' => 2]);

        self::assertSame(['page' => 1, 'limit' => 2, 'total' => 4, 'pages' => 2], $filtered['pagination']);
        self::assertCount(2, $filtered['items']);
        foreach ($filtered['items'] as $order) {
            self::assertSame('new', $order['status']);
        }

        $second = $this->list(['status' => 'new', 'page' => 2, 'limit' => 2]);
        self::assertCount(2, $second['items']);
        $identifiers = array_merge(array_column($filtered['items'], 'marketplace_id'), array_column($second['items'], 'marketplace_id'));
        sort($identifiers);
        self::assertSame(['MP-1001', 'MP-1003', 'MP-1004', 'MP-1009'], $identifiers);

        $empty = $this->list(['status' => 'new', 'page' => 3, 'limit' => 2]);
        self::assertSame([], $empty['items']);
        self::assertSame(4, $empty['pagination']['total']);
        self::assertSame([], $this->list(['status' => 'delivering'])['items']);
    }

    public function testRejectedFirstOccurrenceStillMakesLaterOccurrenceADuplicate(): void
    {
        $valid = $this->fixture()['orders'][0];
        $invalid = $valid;
        $invalid['customer']['phone'] = '123';

        $response = $this->import(['orders' => [$invalid, $valid]]);

        self::assertSame(0, $response['summary']['created']);
        self::assertSame(1, $response['summary']['rejected']);
        self::assertSame(1, $response['summary']['duplicate']);
        self::assertSame('rejected', $response['results'][0]['outcome']);
        self::assertSame('invalid_phone', $response['results'][0]['code']);
        self::assertSame('duplicate', $response['results'][1]['outcome']);
        self::assertSame([], $this->list()['items']);
    }

    public function testMissingRequiredFieldsRejectOnlyTheirOwnRows(): void
    {
        $base = $this->fixture()['orders'][0];
        $missingPaths = [
            ['id'],
            ['status'],
            ['customer', 'name'],
            ['customer', 'phone'],
            ['delivery', 'district'],
            ['delivery', 'address'],
            ['delivery', 'date'],
            ['delivery', 'time_from'],
            ['delivery', 'time_to'],
        ];
        $rows = [];

        foreach ($missingPaths as $index => $path) {
            $row = $base;
            $row['id'] = 'INVALID-'.$index;
            if (1 === count($path)) {
                unset($row[$path[0]]);
            } else {
                unset($row[$path[0]][$path[1]]);
            }
            $rows[] = $row;
        }
        $emptyItems = $base;
        $emptyItems['id'] = 'INVALID-EMPTY-ITEMS';
        $emptyItems['items'] = [];
        $rows[] = $emptyItems;
        $rows[] = $base;

        $response = $this->import(['orders' => $rows]);

        self::assertSame(10, $response['summary']['rejected']);
        self::assertSame(1, $response['summary']['created']);
        foreach (array_slice($response['results'], 0, 10) as $result) {
            self::assertSame('rejected', $result['outcome']);
            self::assertNotEmpty($result['code']);
            self::assertNotEmpty($result['message']);
        }
        self::assertSame('created', $response['results'][10]['outcome']);
        self::assertCount(1, $this->list()['items']);
    }

    public function testMalformedRowsDoNotPreventAValidOrderFromBeingImported(): void
    {
        $valid = $this->fixture()['orders'][0];
        $invalid = $valid;
        $invalid['id'] = 'INVALID-ITEM';
        $invalid['items'][0]['qty'] = 'не число';

        $response = $this->import(['orders' => [null, 'не заказ', $invalid, $valid]]);

        self::assertSame(3, $response['summary']['rejected']);
        self::assertSame(1, $response['summary']['created']);
        self::assertCount(4, $response['results']);
        self::assertSame('created', $response['results'][3]['outcome']);
        self::assertCount(1, $this->list()['items']);
    }

    public function testInvalidBatchShapeAndMalformedJsonAreRejected(): void
    {
        foreach ([[], ['orders' => 'не массив']] as $payload) {
            $this->client->jsonRequest('POST', $this->importUrl(), $payload);
            self::assertContains($this->client->getResponse()->getStatusCode(), [400, 422]);
        }

        $this->client->request('POST', $this->importUrl(), server: ['CONTENT_TYPE' => 'application/json'], content: '{"orders":');
        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $this->list()['items']);
    }

    public function testInvalidListFiltersAreRejected(): void
    {
        foreach (['status=unknown', 'page=0', 'limit=0', 'limit=101'] as $query) {
            $this->client->request('GET', '/api/shops/'.$this->shopId.'/orders?'.$query);
            self::assertContains($this->client->getResponse()->getStatusCode(), [400, 422]);
        }
    }

    private function fixture(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__, 2).'/public/assets/marketplace-orders.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    private function import(array $payload, ?string $shopId = null): array
    {
        $this->client->jsonRequest('POST', $this->importUrl($shopId), $payload);
        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('content-type', 'application/json');

        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function list(array $query = [], ?string $shopId = null): array
    {
        $url = '/api/shops/'.($shopId ?? $this->shopId).'/orders';
        if ([] !== $query) {
            $url .= '?'.http_build_query($query);
        }
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function importUrl(?string $shopId = null): string
    {
        return '/api/shops/'.($shopId ?? $this->shopId).'/orders/import';
    }

    private function ordersByMarketplaceId(array $orders): array
    {
        $byMarketplaceId = [];
        foreach ($orders as $order) {
            $byMarketplaceId[$order['marketplace_id']] = $order;
        }
        ksort($byMarketplaceId);

        return $byMarketplaceId;
    }
}
