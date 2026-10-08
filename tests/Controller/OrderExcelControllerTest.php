<?php

namespace App\Tests\Controller;

use App\Service\Excel\OrderExcelService;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class OrderExcelControllerTest extends WebTestCase
{
    private const string XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    private const array ORDER_HEADERS = [
        'Номер заказа', 'Статус', 'Дата создания', 'Имя покупателя', 'Телефон',
        'Получение', 'Район', 'Адрес', 'Дата доставки', 'Начало интервала',
        'Конец интервала', 'Сумма маркетплейса',
    ];
    private const array ITEM_HEADERS = ['Номер заказа', 'Артикул', 'Название', 'Количество', 'Цена'];

    private KernelBrowser $client;
    private string $shopId;
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->shopId = 'test-excel-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    public function testExcelImportReportsRowsIndividuallyAndCanBeRepeatedWithoutDuplicates(): void
    {
        $valid = $this->rawOrder('XLSX-VALID');
        $invalid = $this->rawOrder('XLSX-INVALID');
        $invalid['customer']['phone'] = '123';
        $path = $this->workbook([$valid, null, $invalid, $valid]);

        $response = $this->upload($path);

        self::assertSame(['total' => 3, 'created' => 1, 'updated' => 0, 'unchanged' => 0, 'duplicate' => 1, 'rejected' => 1], $response['summary']);
        self::assertSame([2, 4, 5], array_column($response['results'], 'source_row'));
        self::assertSame(['created', 'rejected', 'duplicate'], array_column($response['results'], 'outcome'));
        self::assertSame('invalid_phone', $response['results'][1]['code']);
        self::assertNotEmpty($response['results'][1]['message']);
        self::assertSame('duplicate_in_batch', $response['results'][2]['code']);
        $before = $this->list()['items'];
        self::assertCount(1, $before);
        self::assertSame('XLSX-VALID', $before[0]['marketplace_id']);

        $repeated = $this->upload($path);

        self::assertSame(0, $repeated['summary']['created']);
        self::assertSame(1, $repeated['summary']['unchanged']);
        self::assertSame(1, $repeated['summary']['duplicate']);
        self::assertSame(1, $repeated['summary']['rejected']);
        self::assertSame($before, $this->list()['items']);
    }

    public function testExportIncludesEveryShopOrderAcrossAllPagesAndIsolatesOtherShops(): void
    {
        $orders = [];
        for ($number = 1; $number <= 27; ++$number) {
            $orders[] = $this->rawOrder('ALL-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT));
        }
        $this->importJson($orders);
        $other = $this->rawOrder('OTHER-SHOP-ONLY');
        $other['customer']['name'] = 'Покупатель другого магазина';
        $this->importJson([$other], $this->shopId.'-other');
        $page = $this->list();
        self::assertCount(10, $page['items']);
        self::assertSame(27, $page['pagination']['total']);
        self::assertSame(3, $page['pagination']['pages']);

        $path = $this->download('/export/excel');
        $exported = self::getContainer()->get(OrderExcelService::class)->read($path);

        self::assertCount(27, $exported);
        $actualIds = array_column($exported, 'id');
        $expectedIds = array_column($orders, 'id');
        sort($actualIds);
        sort($expectedIds);
        self::assertSame($expectedIds, $actualIds);
        self::assertNotContains('OTHER-SHOP-ONLY', $actualIds);
        self::assertNotContains('Покупатель другого магазина', array_column(array_column($exported, 'customer'), 'name'));
    }

    public function testExportCanBeImportedByAnotherShopWithoutLosingStatusesKopecksOrReviewFlags(): void
    {
        $orders = [];
        foreach (['NEW', 'ACCEPTED', 'IN_DELIVERY', 'DONE', 'CANCELED'] as $index => $status) {
            $raw = $this->rawOrder('000'.($index + 1));
            $raw['status'] = $status;
            $raw['items'] = [
                ['sku' => '00001', 'name' => 'Букет', 'qty' => 3, 'price' => '0.10'],
                ['sku' => '00002', 'name' => 'Открытка', 'qty' => 2, 'price' => '0.29'],
            ];
            $raw['total'] = 0 === $index ? '0.87' : '0.88';
            if (1 === $index) {
                $raw['delivery'] = ['type' => 'pickup'];
            }
            $orders[] = $raw;
        }
        $this->importJson($orders);
        $before = $this->byMarketplaceId($this->list()['items']);
        self::assertTrue($before['0001']['needs_review']);
        self::assertEquals(0.88, $before['0001']['items_total']);
        self::assertEquals(0.87, $before['0001']['marketplace_total']);
        self::assertEquals(300.88, $before['0001']['total']);

        $export = $this->download('/export/excel');
        $targetShop = $this->shopId.'-copy';
        $report = $this->upload($export, $targetShop);

        self::assertSame(5, $report['summary']['created']);
        self::assertSame(0, $report['summary']['rejected']);
        $after = $this->byMarketplaceId($this->list($targetShop)['items']);
        foreach ($before as $marketplaceId => $order) {
            unset($order['id'], $after[$marketplaceId]['id']);
            self::assertSame($order, $after[$marketplaceId], 'Данные заказа '.$marketplaceId.' должны сохраняться при повторном импорте выгрузки.');
        }
    }

    public function testDownloadedTemplateCanImmediatelyBeUploaded(): void
    {
        $path = $this->download('/template/excel');

        $report = $this->upload($path);

        self::assertSame(1, $report['summary']['total']);
        self::assertSame(1, $report['summary']['created']);
        self::assertSame(2, $report['results'][0]['source_row']);
        $order = $this->list()['items'][0];
        self::assertSame('pickup', $order['delivery']['type']);
        self::assertEquals(0, $order['delivery_cost']);
        self::assertFalse($order['needs_review']);
    }

    public function testAnEmptyShopExportsAValidWorkbookWithoutOrders(): void
    {
        $path = $this->download('/export/excel');

        self::assertSame([], self::getContainer()->get(OrderExcelService::class)->read($path));
    }

    #[DataProvider('invalidUploads')]
    public function testInvalidUploadsReturnValidationErrorsAndPersistNoOrders(string $kind): void
    {
        $files = [];
        if ('missing' !== $kind) {
            $path = $this->temporaryPath();
            if ('corrupt' === $kind) {
                file_put_contents($path, 'не Excel');
            } elseif ('oversized' === $kind) {
                $stream = fopen($path, 'w');
                ftruncate($stream, 10 * 1024 * 1024 + 1);
                fclose($stream);
            } else {
                self::getContainer()->get(OrderExcelService::class)->writeTemplate($path);
            }
            $name = 'extension' === $kind ? 'orders.csv' : 'orders.xlsx';
            $files['file'] = new UploadedFile($path, $name, self::XLSX_MIME, test: true);
        }

        $this->client->request('POST', $this->url('/import/excel'), files: $files, server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(422);
        self::assertNotEmpty($this->client->getResponse()->getContent());
        self::assertSame([], $this->list()['items']);
    }

    public static function invalidUploads(): iterable
    {
        yield 'missing upload' => ['missing'];
        yield 'wrong extension' => ['extension'];
        yield 'too large' => ['oversized'];
        yield 'broken workbook' => ['corrupt'];
    }

    public function testInvalidWorkbookStructureRejectsTheWholeUploadBeforePersistingValidRows(): void
    {
        $path = $this->workbook([$this->rawOrder('WILL-NOT-SAVE')], ['UNKNOWN-ORDER', 'B-1', 'Букет без заказа', 1, 100]);

        $this->client->request('POST', $this->url('/import/excel'), files: [
            'file' => new UploadedFile($path, 'orders.xlsx', self::XLSX_MIME, test: true),
        ], server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->list()['items']);
    }

    private function rawOrder(string $id): array
    {
        $fixture = json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/marketplace-orders.json'), true, flags: JSON_THROW_ON_ERROR);
        $order = $fixture['orders'][0];
        $order['id'] = $id;

        return $order;
    }

    private function importJson(array $orders, ?string $shop = null): void
    {
        $this->client->jsonRequest('POST', $this->url('/import', $shop), ['orders' => $orders]);
        self::assertResponseStatusCodeSame(200);
        $report = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(count($orders), $report['summary']['created']);
        self::assertSame(0, $report['summary']['rejected']);
    }

    private function upload(string $path, ?string $shop = null): array
    {
        $this->client->request('POST', $this->url('/import/excel', $shop), files: [
            'file' => new UploadedFile($path, 'orders.xlsx', self::XLSX_MIME, test: true),
        ], server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('content-type', 'application/json');

        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function download(string $suffix): string
    {
        $this->client->request('GET', $this->url($suffix));
        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('content-type', self::XLSX_MIME);
        $disposition = $this->client->getResponse()->headers->get('content-disposition');
        self::assertStringContainsString('attachment;', $disposition);
        self::assertStringContainsString('.xlsx', $disposition);
        $content = $this->client->getInternalResponse()->getContent();
        self::assertStringStartsWith('PK', $content);
        $path = $this->temporaryPath();
        file_put_contents($path, $content);

        return $path;
    }

    private function list(?string $shop = null): array
    {
        $this->client->request('GET', $this->url('', $shop).'?limit=10');
        self::assertResponseStatusCodeSame(200);

        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function url(string $suffix, ?string $shop = null): string
    {
        return '/api/shops/'.($shop ?? $this->shopId).'/orders'.$suffix;
    }

    private function temporaryPath(): string
    {
        $path = sys_get_temp_dir().'/excel-http-test-'.bin2hex(random_bytes(8)).'.xlsx';
        $this->files[] = $path;

        return $path;
    }

    private function workbook(array $orders, ?array $orphanItem = null): string
    {
        $path = $this->temporaryPath();
        $writer = new Writer();
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Заказы');
        $writer->addRow(Row::fromValues(self::ORDER_HEADERS));
        foreach ($orders as $order) {
            if (null === $order) {
                $writer->addRow(Row::fromValues([]));
                continue;
            }
            $delivery = $order['delivery'];
            $writer->addRow(Row::fromValues([
                $order['id'], $order['status'], $order['created_at'], $order['customer']['name'], $order['customer']['phone'],
                $delivery['type'], $delivery['district'] ?? '', $delivery['address'] ?? '', $delivery['date'] ?? '',
                $delivery['time_from'] ?? '', $delivery['time_to'] ?? '', $order['total'],
            ]));
        }
        $writer->addNewSheetAndMakeItCurrent()->setName('Позиции');
        $writer->addRow(Row::fromValues(self::ITEM_HEADERS));
        $seen = [];
        foreach ($orders as $order) {
            if (null === $order || isset($seen[$order['id']])) {
                continue;
            }
            $seen[$order['id']] = true;
            foreach ($order['items'] as $item) {
                $writer->addRow(Row::fromValues([$order['id'], $item['sku'], $item['name'], $item['qty'], $item['price']]));
            }
        }
        if (null !== $orphanItem) {
            $writer->addRow(Row::fromValues($orphanItem));
        }
        $writer->close();

        return $path;
    }

    private function byMarketplaceId(array $orders): array
    {
        $result = [];
        foreach ($orders as $order) {
            $result[$order['marketplace_id']] = $order;
        }
        ksort($result);

        return $result;
    }
}
