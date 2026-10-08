<?php

namespace App\Tests\Service;

use App\DTO\Order\OrderViewDto;
use App\Exception\InvalidExcelFileException;
use App\Exception\InvalidOrderException;
use App\Service\Excel\OrderExcelService;
use App\Service\Import\OrderNormalizer;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OrderExcelServiceTest extends KernelTestCase
{
    private const array ORDER_HEADERS = [
        'Номер заказа', 'Статус', 'Дата создания', 'Имя покупателя', 'Телефон',
        'Получение', 'Район', 'Адрес', 'Дата доставки', 'Начало интервала',
        'Конец интервала', 'Сумма маркетплейса',
    ];
    private const array ITEM_HEADERS = ['Номер заказа', 'Артикул', 'Название', 'Количество', 'Цена'];

    private OrderExcelService $excel;
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->excel = self::getContainer()->get(OrderExcelService::class);
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

    public function testReadsTwoSheetsAndPreservesTextIdentifiersAndItemAssociations(): void
    {
        $path = $this->workbook([
            'Заказы' => [self::ORDER_HEADERS, self::orderRow('0000123'), self::orderRow('SECOND', phone: 79001112233)],
            'Позиции' => [self::ITEM_HEADERS, ['SECOND', 'B-2', 'Второй букет', '2', '12.29'], ['0000123', '00001', 'Первый букет', 1.0, 0.1], ['0000123', '00002', 'Открытка', 3, 0.29]],
        ]);

        $orders = $this->excel->read($path);

        self::assertCount(2, $orders);
        self::assertSame('0000123', $orders[0]['id']);
        self::assertSame('2026-10-08T09:00:00+03:00', $orders[0]['created_at']);
        self::assertSame('+79001234567', $orders[0]['customer']['phone']);
        self::assertSame('2026-10-10', $orders[0]['delivery']['date']);
        self::assertSame('14:00', $orders[0]['delivery']['time_from']);
        self::assertSame('16:00', $orders[0]['delivery']['time_to']);
        self::assertSame(['00001', '00002'], array_column($orders[0]['items'], 'sku'));
        self::assertSame([1, 3], array_column($orders[0]['items'], 'qty'));
        self::assertSame('79001112233', $orders[1]['customer']['phone']);
        self::assertSame(2, $orders[1]['items'][0]['qty']);
        self::assertEquals(12.29, $orders[1]['items'][0]['price']);
    }

    public function testKeepsDuplicateOrdersAndPhysicalSourceRowsAcrossBlankLines(): void
    {
        $path = $this->workbook([
            'Заказы' => [self::ORDER_HEADERS, self::orderRow('SAME'), [], self::orderRow('SAME')],
            'Позиции' => [self::ITEM_HEADERS, ['SAME', 'B-1', 'Букет', 1, 3200]],
        ]);

        $orders = $this->excel->read($path);

        self::assertSame(['SAME', 'SAME'], array_column($orders, 'id'));
        self::assertSame([2, 4], array_column($orders, '_source_row'));
        self::assertSame($orders[0]['items'], $orders[1]['items']);
    }

    public function testReadsNativeExcelDatesAndNumericTimeCellsUsingMoscowTime(): void
    {
        $timezone = new \DateTimeZone('Europe/Moscow');
        $row = self::orderRow('EXCEL-DATES');
        $row[2] = new \DateTimeImmutable('2026-10-08 09:00:00', $timezone);
        $row[8] = new \DateTimeImmutable('2026-10-10 00:00:00', $timezone);
        $row[9] = 14 / 24;
        $row[10] = 16 / 24;
        $path = $this->workbook([
            'Заказы' => [self::ORDER_HEADERS, $row],
            'Позиции' => [self::ITEM_HEADERS, ['EXCEL-DATES', 'B-1', 'Букет', 1, 3200]],
        ]);

        $raw = $this->excel->read($path)[0];

        self::assertSame('2026-10-08T09:00:00+03:00', $raw['created_at']);
        self::assertSame('2026-10-10', $raw['delivery']['date']);
        self::assertSame('14:00', $raw['delivery']['time_from']);
        self::assertSame('16:00', $raw['delivery']['time_to']);
        $order = self::getContainer()->get(OrderNormalizer::class)->normalize($raw);
        self::assertSame('2026-10-10T14:00:00+03:00', $order->deliveryStartsAt->format(\DateTimeInterface::ATOM));
    }

    public function testRecordValidationRemainsTheImportNormalizersResponsibility(): void
    {
        $row = self::orderRow('INVALID');
        $row[4] = 'не телефон';
        $path = $this->workbook([
            'Заказы' => [self::ORDER_HEADERS, $row],
            'Позиции' => [self::ITEM_HEADERS, ['INVALID', 'B-1', 'Букет', 'не число', 3200]],
        ]);

        $orders = $this->excel->read($path);

        self::assertCount(1, $orders);
        self::assertSame('не телефон', $orders[0]['customer']['phone']);
        self::assertSame('не число', $orders[0]['items'][0]['qty']);
        $this->expectException(InvalidOrderException::class);
        self::getContainer()->get(OrderNormalizer::class)->normalize($orders[0]);
    }

    public function testRejectsAFileThatIsNotAnXlsxWorkbook(): void
    {
        $path = $this->temporaryPath();
        file_put_contents($path, 'Это текстовый файл, а не Excel.');

        $this->assertInvalidWorkbook($path);
    }

    #[DataProvider('invalidStructures')]
    public function testRejectsMissingSheetsOrRequiredColumns(array $sheets): void
    {
        $this->assertInvalidWorkbook($this->workbook($sheets));
    }

    public static function invalidStructures(): iterable
    {
        yield 'missing order sheet' => [['Позиции' => [self::ITEM_HEADERS]]];
        yield 'missing item sheet' => [['Заказы' => [self::ORDER_HEADERS]]];
        yield 'missing required order column' => [[
            'Заказы' => [array_slice(self::ORDER_HEADERS, 0, -1)],
            'Позиции' => [self::ITEM_HEADERS],
        ]];
        yield 'missing required item column' => [[
            'Заказы' => [self::ORDER_HEADERS],
            'Позиции' => [array_slice(self::ITEM_HEADERS, 0, -1)],
        ]];
    }

    #[DataProvider('formulaLocations')]
    public function testRejectsFormulaCellsInsteadOfTreatingTheirCachedValueAsInput(string $sheet, int $column): void
    {
        $order = self::orderRow('FORMULA');
        $item = ['FORMULA', 'B-1', 'Букет', 1, 3200];
        if ('Заказы' === $sheet) {
            $order[$column] = new FormulaCell('="Анна"', 'Анна');
        } else {
            $item[$column] = new FormulaCell('=1+1', 2);
        }

        $this->assertInvalidWorkbook($this->workbook([
            'Заказы' => [self::ORDER_HEADERS, $order],
            'Позиции' => [self::ITEM_HEADERS, $item],
        ]));
    }

    public static function formulaLocations(): iterable
    {
        yield 'customer formula' => ['Заказы', 3];
        yield 'quantity formula' => ['Позиции', 3];
    }

    public function testRejectsAPositionWithoutItsOrder(): void
    {
        $this->assertInvalidWorkbook($this->workbook([
            'Заказы' => [self::ORDER_HEADERS, self::orderRow('PRESENT')],
            'Позиции' => [self::ITEM_HEADERS, ['MISSING', 'B-1', 'Букет', 1, 3200]],
        ]));
    }

    public function testAWorkbookWithOnlyHeadersHasNoOrders(): void
    {
        self::assertSame([], $this->excel->read($this->workbook([
            'Заказы' => [self::ORDER_HEADERS],
            'Позиции' => [self::ITEM_HEADERS],
        ])));
    }

    public function testExportContainsCalculatedColumnsAndRoundTripsExactKopecksAndText(): void
    {
        $view = new OrderViewDto(
            id: 1,
            marketplaceId: '0000123',
            status: 'accepted',
            createdAt: '2026-10-08T09:00:00+03:00',
            customer: ['name' => '=Имя покупателя', 'phone' => '+79001234567'],
            delivery: [
                'type' => 'delivery', 'district' => 'centre', 'address' => '=Адрес',
                'starts_at' => '2026-10-10T14:00:00+03:00', 'ends_at' => '2026-10-10T16:00:00+03:00',
            ],
            items: [
                ['sku' => '00001', 'name' => '=Букет', 'qty' => 3, 'price' => 0.1],
                ['sku' => '00002', 'name' => 'Открытка', 'qty' => 2, 'price' => 0.29],
            ],
            itemsTotalCents: 88,
            marketplaceTotalCents: 87,
            deliveryCostCents: 30000,
            grandTotalCents: 30088,
            needsReview: true,
        );
        $path = $this->temporaryPath();

        $orders = (static function () use ($view): iterable {
            yield $view;
        })();
        $this->excel->write($orders, $path);

        $sheets = $this->readSheets($path);
        self::assertSame([...self::ORDER_HEADERS, 'Сумма товаров', 'Стоимость доставки', 'Итого', 'Нужна проверка'], $sheets['Заказы'][0]);
        self::assertSame(self::ITEM_HEADERS, $sheets['Позиции'][0]);
        self::assertCount(3, $sheets['Позиции']);
        self::assertEquals([0.88, 300, 300.88], array_slice($sheets['Заказы'][1], 12, 3));

        $raw = $this->excel->read($path)[0];
        self::assertSame('ACCEPTED', $raw['status']);
        self::assertSame('0000123', $raw['id']);
        self::assertSame('=Имя покупателя', $raw['customer']['name']);
        self::assertSame('=Адрес', $raw['delivery']['address']);
        self::assertSame('=Букет', $raw['items'][0]['name']);
        self::assertSame('00001', $raw['items'][0]['sku']);
        $order = self::getContainer()->get(OrderNormalizer::class)->normalize($raw);
        self::assertSame(88, $order->itemsTotalCents);
        self::assertSame(87, $order->marketplaceTotalCents);
        self::assertSame(30000, $order->deliveryCostCents);
        self::assertSame(30088, $order->grandTotalCents);
        self::assertTrue($order->needsReview);
    }

    public function testTemplateIsAUsablePickupOrderWithMatchingItems(): void
    {
        $path = $this->temporaryPath();
        $this->excel->writeTemplate($path);

        $sheets = $this->readSheets($path);
        self::assertSame(self::ORDER_HEADERS, $sheets['Заказы'][0]);
        self::assertSame(self::ITEM_HEADERS, $sheets['Позиции'][0]);
        $orders = $this->excel->read($path);
        self::assertCount(1, $orders);
        $order = self::getContainer()->get(OrderNormalizer::class)->normalize($orders[0]);
        self::assertSame('pickup', $order->deliveryType);
        self::assertSame(0, $order->deliveryCostCents);
        self::assertFalse($order->needsReview);
    }

    private function assertInvalidWorkbook(string $path): void
    {
        try {
            $this->excel->read($path);
            self::fail('Некорректный файл должен быть отклонён до импорта.');
        } catch (InvalidExcelFileException $exception) {
            self::assertMatchesRegularExpression('/[А-Яа-яЁё]/u', $exception->getMessage());
        }
    }

    private static function orderRow(string $id, string|int $phone = '+79001234567'): array
    {
        return [$id, 'NEW', '2026-10-08T09:00:00+03:00', 'Анна Лебедева', $phone, 'delivery', 'centre', 'ул. Садовая, 10', '2026-10-10', '14:00', '16:00', 3200];
    }

    private function temporaryPath(): string
    {
        $path = sys_get_temp_dir().'/excel-service-test-'.bin2hex(random_bytes(8)).'.xlsx';
        $this->files[] = $path;

        return $path;
    }

    private function workbook(array $sheets): string
    {
        $path = $this->temporaryPath();
        $writer = new Writer();
        $writer->openToFile($path);
        foreach ($sheets as $name => $rows) {
            $sheet = $writer->getCurrentSheet();
            if ('Sheet1' !== $sheet->getName()) {
                $sheet = $writer->addNewSheetAndMakeItCurrent();
            }
            $sheet->setName($name);
            foreach ($rows as $values) {
                $writer->addRow(new Row(array_map(static fn (mixed $value): Cell => $value instanceof Cell ? $value : Cell::fromValue($value), $values)));
            }
        }
        $writer->close();

        return $path;
    }

    private function readSheets(string $path): array
    {
        $reader = new Reader();
        $reader->open($path);
        $sheets = [];
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $sheets[$sheet->getName()] = [];
                foreach ($sheet->getRowIterator() as $row) {
                    $sheets[$sheet->getName()][] = $row->toArray();
                }
            }
        } finally {
            $reader->close();
        }

        return $sheets;
    }
}
