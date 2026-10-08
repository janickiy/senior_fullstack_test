<?php

namespace App\Service\Excel;

use App\DTO\Order\OrderViewDto;
use App\Enum\OrderStatus;
use App\Exception\InvalidExcelFileException;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Cell\TextRunCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\XLSX\Options as ReaderOptions;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Reader\XLSX\Sheet as ReaderSheet;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\Common\Entity\Sheet as WriterSheet;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options as WriterOptions;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Читает и создаёт Excel-файлы заказов с отдельным листом товарных позиций.
 * Проверяет структуру файла целиком до импорта; проверку значений заказа,
 * расчёт доставки и сохранение выполняет существующий сервис импорта.
 */
final class OrderExcelService
{
    private const string ORDERS_SHEET = 'Заказы';
    private const string ITEMS_SHEET = 'Позиции';
    private const int MAX_ORDERS = 10000;
    private const int MAX_ITEMS = 50000;
    private const int MAX_PHYSICAL_ROWS = 100001;
    private const int MAX_FILE_BYTES = 16 * 1024 * 1024;
    private const int MAX_UNCOMPRESSED_BYTES = 64 * 1024 * 1024;
    private const int MAX_ENTRY_BYTES = 32 * 1024 * 1024;
    private const int MAX_ZIP_ENTRIES = 1024;

    private const array ORDER_HEADERS = [
        'Номер заказа', 'Статус', 'Дата создания', 'Имя покупателя', 'Телефон',
        'Получение', 'Район', 'Адрес', 'Дата доставки', 'Начало интервала',
        'Конец интервала', 'Сумма маркетплейса',
    ];
    private const array CALCULATED_HEADERS = [
        'Сумма товаров', 'Стоимость доставки', 'Итого', 'Нужна проверка',
    ];
    private const array ITEM_HEADERS = [
        'Номер заказа', 'Артикул', 'Название', 'Количество', 'Цена',
    ];

    /**
     * Возвращает сырые заказы для обычного импорта и номер исходной строки каждого заказа.
     *
     * @return list<array<string, mixed>>
     *
     * @throws InvalidExcelFileException При повреждении файла или ошибке структуры листов.
     */
    public function read(string $path): array
    {
        $use1904Dates = $this->checkArchive($path);
        $reader = new Reader(new ReaderOptions(SHOULD_PRESERVE_EMPTY_ROWS: true));

        try {
            $reader->open($path);
            $sheets = [];

            foreach ($reader->getSheetIterator() as $sheet) {
                $name = $sheet->getName();
                $headers = match ($name) {
                    self::ORDERS_SHEET => self::ORDER_HEADERS,
                    self::ITEMS_SHEET => self::ITEM_HEADERS,
                    default => [],
                };

                if (isset($sheets[$name])) {
                    throw new InvalidExcelFileException('В файле повторяется лист «'.$name.'».');
                }

                $sheets[$name] = $this->readSheet($sheet, $headers);
            }

            foreach ([self::ORDERS_SHEET, self::ITEMS_SHEET] as $name) {
                if (!isset($sheets[$name])) {
                    throw new InvalidExcelFileException('В файле отсутствует обязательный лист «'.$name.'».');
                }
            }

            return $this->assembleOrders($sheets[self::ORDERS_SHEET], $sheets[self::ITEMS_SHEET], $use1904Dates);
        } catch (InvalidExcelFileException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new InvalidExcelFileException('Не удалось прочитать Excel-файл. Проверьте, что он не повреждён и сохранён в формате .xlsx.', previous: $exception);
        } finally {
            $reader->close();
        }
    }

    /**
     * Сохраняет заказы и позиции в Excel за один проход, включая рассчитанные суммы.
     *
     * @param iterable<OrderViewDto> $orders
     */
    public function write(iterable $orders, string $path): void
    {
        $this->writeWorkbook($orders, $path, true);
    }

    /** Создаёт заполняемый шаблон с одним примером заказа на самовывоз. */
    public function writeTemplate(string $path): void
    {
        $example = new OrderViewDto(
            id: 0,
            marketplaceId: 'MP-EXAMPLE-1',
            status: OrderStatus::New->value,
            createdAt: (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Moscow')))->format(\DateTimeInterface::ATOM),
            customer: ['name' => 'Анна Иванова', 'phone' => '+79001234567'],
            delivery: ['type' => 'pickup', 'district' => null, 'address' => null, 'starts_at' => null, 'ends_at' => null],
            items: [['sku' => 'B-001', 'name' => 'Букет', 'qty' => 1, 'price' => 1500]],
            itemsTotalCents: 150000,
            marketplaceTotalCents: 150000,
            deliveryCostCents: 0,
            grandTotalCents: 150000,
            needsReview: false,
        );
        $this->writeWorkbook([$example], $path, false);
    }

    /** Ограничивает размер ZIP до чтения XML и определяет систему дат книги Excel. */
    private function checkArchive(string $path): bool
    {
        if (!is_file($path) || !is_readable($path) || filesize($path) > self::MAX_FILE_BYTES) {
            throw new InvalidExcelFileException('Excel-файл недоступен или превышает допустимый размер.');
        }

        $archive = new \ZipArchive();

        if (true !== $archive->open($path, \ZipArchive::CHECKCONS)) {
            throw new InvalidExcelFileException('Загрузите корректный Excel-файл в формате .xlsx.');
        }

        try {
            if ($archive->numFiles > self::MAX_ZIP_ENTRIES) {
                throw new InvalidExcelFileException('Excel-файл содержит слишком много внутренних файлов.');
            }

            $totalSize = 0;
            $xmlEntries = [];

            for ($index = 0; $index < $archive->numFiles; ++$index) {
                $entry = $archive->statIndex($index);

                if (false === $entry || $entry['size'] > self::MAX_ENTRY_BYTES) {
                    throw new InvalidExcelFileException('Содержимое Excel-файла превышает допустимый размер.');
                }

                $totalSize += $entry['size'];

                if ($totalSize > self::MAX_UNCOMPRESSED_BYTES) {
                    throw new InvalidExcelFileException('Распакованное содержимое Excel-файла превышает 64 МиБ.');
                }

                if (str_ends_with(strtolower($entry['name']), 'vbaproject.bin')) {
                    throw new InvalidExcelFileException('Файлы Excel с макросами не поддерживаются. Сохраните файл как .xlsx.');
                }

                if (str_ends_with($entry['name'], '.xml')) {
                    $xmlEntries[] = $entry['name'];
                }
            }

            $contentTypes = $archive->getFromName('[Content_Types].xml');
            $workbook = $archive->getFromName('xl/workbook.xml');

            if (false === $contentTypes || false === $workbook || !str_contains($contentTypes, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml')) {
                throw new InvalidExcelFileException('Файл не является книгой Excel в формате .xlsx.');
            }

            $use1904Dates = false;

            foreach ($xmlEntries as $name) {
                $use1904Dates = $this->checkXml($path, $name) || $use1904Dates;
            }

            return $use1904Dates;
        } finally {
            $archive->close();
        }
    }

    /** Проверяет XML потоково и отклоняет настоящие формулы, сохраняя буквальный текст «=…». */
    private function checkXml(string $path, string $entryName): bool
    {
        $previousErrorMode = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $xml = new \XMLReader();
        $use1904Dates = false;

        try {
            if (!$xml->open('zip://'.$path.'#'.$entryName, null, \LIBXML_NONET)) {
                throw new InvalidExcelFileException('Не удалось прочитать внутреннюю структуру Excel-файла.');
            }

            while ($xml->read()) {
                if (\XMLReader::DOC_TYPE === $xml->nodeType) {
                    throw new InvalidExcelFileException('Excel-файл содержит неподдерживаемое описание XML.');
                }

                if (\XMLReader::ELEMENT !== $xml->nodeType) {
                    continue;
                }

                if ('f' === $xml->localName && ('' === $xml->namespaceURI || str_contains($xml->namespaceURI, 'spreadsheetml'))) {
                    throw new InvalidExcelFileException('В Excel-файле найдена формула. Формулы не поддерживаются: замените их значениями.');
                }

                if ('xl/workbook.xml' === $entryName && 'workbookPr' === $xml->localName) {
                    $use1904Dates = \in_array($xml->getAttribute('date1904'), ['1', 'true'], true);
                }
            }

            foreach (libxml_get_errors() as $error) {
                if ($error->level >= \LIBXML_ERR_ERROR) {
                    throw new InvalidExcelFileException('Внутренняя структура Excel-файла повреждена. Сохраните файл заново в формате .xlsx.');
                }
            }

            return $use1904Dates;
        } finally {
            $xml->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }
    }

    /** Читает строки листа, проверяя заголовки и ограничения количества строк. */
    private function readSheet(ReaderSheet $sheet, array $requiredHeaders): array
    {
        $columns = null;
        $rows = [];
        $limit = self::ORDERS_SHEET === $sheet->getName() ? self::MAX_ORDERS : self::MAX_ITEMS;

        foreach ($sheet->getRowIterator() as $rowNumber => $row) {
            if ($rowNumber > self::MAX_PHYSICAL_ROWS) {
                throw new InvalidExcelFileException('В листе «'.$sheet->getName().'» слишком много строк. Удалите лишние пустые строки.');
            }

            if ([] === $requiredHeaders) {
                continue;
            }

            $values = array_map(static fn (Cell $cell): mixed => $cell instanceof TextRunCell ? $cell->getStringValue() : $cell->getValue(), $row->cells);

            if (1 === $rowNumber) {
                $columns = $this->headerColumns($values, $requiredHeaders, $sheet->getName());
                continue;
            }

            if (null === $columns) {
                throw new InvalidExcelFileException('Первая строка листа «'.$sheet->getName().'» должна содержать заголовки.');
            }

            $mapped = [];

            foreach ($columns as $name => $index) {
                $mapped[$name] = $values[$index] ?? null;
            }

            if ([] === array_filter($mapped, static fn (mixed $value): bool => null !== $value && (!\is_string($value) || '' !== trim($value)))) {
                continue;
            }

            if (\count($rows) >= $limit) {
                throw new InvalidExcelFileException('Лист «'.$sheet->getName().'» содержит больше '.$limit.' записей.');
            }

            $mapped['_source_row'] = $rowNumber;
            $rows[] = $mapped;
        }

        if ([] !== $requiredHeaders && null === $columns) {
            throw new InvalidExcelFileException('Лист «'.$sheet->getName().'» пуст: добавьте заголовки в первую строку.');
        }

        return $rows;
    }

    /** Находит обязательные столбцы по названию, независимо от их порядка. */
    private function headerColumns(array $values, array $requiredHeaders, string $sheetName): array
    {
        $columns = [];

        foreach ($values as $index => $value) {
            if (!\is_string($value) || '' === trim($value)) {
                continue;
            }

            $name = trim($value);

            if (isset($columns[$name])) {
                throw new InvalidExcelFileException('В листе «'.$sheetName.'» повторяется столбец «'.$name.'».');
            }

            $columns[$name] = $index;
        }

        $missing = array_diff($requiredHeaders, array_keys($columns));

        if ([] !== $missing) {
            throw new InvalidExcelFileException('В листе «'.$sheetName.'» отсутствуют столбцы: '.implode(', ', $missing).'.');
        }

        return array_intersect_key($columns, array_flip($requiredHeaders));
    }

    /** Связывает позиции с заказами по номеру, сохраняя порядок и повторяющиеся заказы. */
    private function assembleOrders(array $orderRows, array $itemRows, bool $use1904Dates): array
    {
        $orders = [];
        $knownIds = [];

        foreach ($orderRows as $row) {
            $id = $this->text($row['Номер заказа']);
            $orders[] = [
                'id' => $id,
                'status' => $this->text($row['Статус']),
                'created_at' => $this->dateValue($row['Дата создания'], 'created', $use1904Dates),
                'customer' => ['name' => $this->text($row['Имя покупателя']), 'phone' => $this->text($row['Телефон'])],
                'delivery' => [
                    'type' => $this->text($row['Получение']),
                    'district' => $this->text($row['Район']),
                    'address' => $this->text($row['Адрес']),
                    'date' => $this->dateValue($row['Дата доставки'], 'date', $use1904Dates),
                    'time_from' => $this->dateValue($row['Начало интервала'], 'time', $use1904Dates),
                    'time_to' => $this->dateValue($row['Конец интервала'], 'time', $use1904Dates),
                ],
                'items' => [],
                'total' => $row['Сумма маркетплейса'],
                '_source_row' => $row['_source_row'],
            ];

            if (\is_string($id) && '' !== $id) {
                $knownIds[$id] = true;
            }
        }

        $itemsById = [];

        foreach ($itemRows as $row) {
            $id = $this->text($row['Номер заказа']);

            if (!\is_string($id) || '' === $id || !isset($knownIds[$id])) {
                throw new InvalidExcelFileException('Лист «Позиции», строка '.$row['_source_row'].': номер заказа отсутствует в листе «Заказы».');
            }

            $itemsById[$id][] = [
                'sku' => $this->text($row['Артикул'] ?? ''),
                'name' => $this->text($row['Название'] ?? ''),
                'qty' => $this->quantity($row['Количество']),
                'price' => $row['Цена'],
            ];
        }

        foreach ($orders as &$order) {
            if (\is_string($order['id'])) {
                $order['items'] = $itemsById[$order['id']] ?? [];
            }
        }
        unset($order);

        return $orders;
    }

    /** Приводит текстовые поля Excel, включая числовые номера и телефоны, к строкам. */
    private function text(mixed $value): mixed
    {
        if (\is_string($value)) {
            return trim($value);
        }

        if (\is_int($value)) {
            return (string) $value;
        }

        if (\is_float($value) && is_finite($value) && floor($value) === $value) {
            return number_format($value, 0, '.', '');
        }

        return $value;
    }

    /** Преобразует только корректные целые количества; остальные значения проверит валидатор. */
    private function quantity(mixed $value): mixed
    {
        if (\is_float($value) && is_finite($value) && floor($value) === $value && $value >= \PHP_INT_MIN && $value < \PHP_INT_MAX) {
            return (int) $value;
        }

        if (\is_string($value)) {
            $value = trim($value);

            if (preg_match('/^[+-]?[0-9]+(?:\.0+)?$/D', $value)) {
                $digits = preg_replace('/\.0+$/D', '', $value);
                $negative = str_starts_with($digits, '-');
                $digits = ltrim(ltrim($digits, '+-'), '0');
                $integer = filter_var(($negative && '' !== $digits ? '-' : '').('' === $digits ? '0' : $digits), \FILTER_VALIDATE_INT);

                if (false !== $integer) {
                    return $integer;
                }
            }
        }

        return $value;
    }

    /** Приводит Excel-даты, времена и поддерживаемые строки к формату API по московскому времени. */
    private function dateValue(mixed $value, string $kind, bool $use1904Dates): mixed
    {
        $timezone = new \DateTimeZone('Europe/Moscow');
        $date = null;

        if ($value instanceof \DateTimeInterface) {
            $date = new \DateTimeImmutable($value->format('Y-m-d H:i:s'), $timezone);
        } elseif ((\is_int($value) || \is_float($value)) && is_finite((float) $value) && $value >= 0 && $value < ('time' === $kind ? 1 : 2958466)) {
            $base = $use1904Dates ? '1904-01-01' : '1899-12-30';
            $date = (new \DateTimeImmutable($base, $timezone))->modify('+'.(int) round($value * 86400).' seconds');
        } elseif (\is_string($value)) {
            $value = trim($value);

            if ('created' === $kind && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
                $date = $this->parseDate($value, \DateTimeInterface::RFC3339, $timezone)?->setTimezone($timezone);
            } else {
                $formats = match ($kind) {
                    'created' => ['Y-m-d H:i:s', 'Y-m-d H:i', 'd.m.Y H:i:s', 'd.m.Y H:i'],
                    'date' => ['Y-m-d', 'd.m.Y'],
                    'time' => ['H:i', 'H:i:s'],
                };

                foreach ($formats as $format) {
                    $date = $this->parseDate($value, $format, $timezone);

                    if (null !== $date) {
                        break;
                    }
                }
            }
        }

        return null === $date ? $value : $date->format(match ($kind) {
            'created' => \DateTimeInterface::ATOM,
            'date' => 'Y-m-d',
            'time' => 'H:i',
        });
    }

    /** Читает дату строго по формату, без исправления некорректных дней и месяцев. */
    private function parseDate(string $value, string $format, \DateTimeZone $timezone): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!'.$format, $value, $timezone);
        $errors = \DateTimeImmutable::getLastErrors();

        return false !== $date && (false === $errors || (0 === $errors['warning_count'] && 0 === $errors['error_count'])) ? $date : null;
    }

    /** Записывает оба листа потоково, без повторного обхода переданного списка заказов. */
    private function writeWorkbook(iterable $orders, string $path, bool $includeCalculated): void
    {
        $style = new Style(fontName: 'Calibri', fontSize: 11, shouldWrapText: true);
        $writer = new Writer(new WriterOptions(FALLBACK_STYLE: $style, SHOULD_CREATE_NEW_SHEETS_AUTOMATICALLY: false, SHOULD_USE_INLINE_STRINGS: false));
        $writer->openToFile($path);

        try {
            $orderHeaders = $includeCalculated ? [...self::ORDER_HEADERS, ...self::CALCULATED_HEADERS] : self::ORDER_HEADERS;
            $ordersSheet = $this->prepareSheet($writer, self::ORDERS_SHEET, $orderHeaders);
            $itemsSheet = $this->prepareSheet($writer, self::ITEMS_SHEET, self::ITEM_HEADERS, true);
            $ordersSheet->setColumnWidth(24, 3);
            $ordersSheet->setColumnWidth(30, 4);
            $ordersSheet->setColumnWidth(40, 8);
            $itemsSheet->setColumnWidth(35, 3);
            $textStyle = $style->withFormat('@');
            $moneyStyle = $style->withFormat('0.00');
            $orderStyles = [
                0 => $textStyle, 2 => $style->withFormat('yyyy-mm-dd hh:mm:ss'), 4 => $textStyle,
                8 => $style->withFormat('yyyy-mm-dd'), 9 => $style->withFormat('hh:mm'),
                10 => $style->withFormat('hh:mm'), 11 => $moneyStyle,
                12 => $moneyStyle, 13 => $moneyStyle, 14 => $moneyStyle,
            ];
            $timezone = new \DateTimeZone('Europe/Moscow');

            foreach ($orders as $order) {
                $startsAt = null === $order->delivery['starts_at'] ? null : (new \DateTimeImmutable($order->delivery['starts_at']))->setTimezone($timezone);
                $endsAt = null === $order->delivery['ends_at'] ? null : (new \DateTimeImmutable($order->delivery['ends_at']))->setTimezone($timezone);
                $values = [
                    $order->marketplaceId, OrderStatus::from($order->status)->toMarketplace(),
                    (new \DateTimeImmutable($order->createdAt))->setTimezone($timezone),
                    $order->customer['name'], $order->customer['phone'], $order->delivery['type'],
                    $order->delivery['district'], $order->delivery['address'],
                    $startsAt, $startsAt, $endsAt, OrderViewDto::rubles($order->marketplaceTotalCents),
                ];

                if ($includeCalculated) {
                    array_push($values,
                        OrderViewDto::rubles($order->itemsTotalCents),
                        OrderViewDto::rubles($order->deliveryCostCents),
                        OrderViewDto::rubles($order->grandTotalCents),
                        $order->needsReview ? 'Да' : 'Нет',
                    );
                }

                $writer->setCurrentSheet($ordersSheet);
                $writer->addRow($this->safeRow($values, $style, $orderStyles));
                $writer->setCurrentSheet($itemsSheet);

                foreach ($order->items as $item) {
                    $writer->addRow($this->safeRow([
                        $order->marketplaceId, $item['sku'], $item['name'], $item['qty'], $item['price'],
                    ], $style, [0 => $textStyle, 1 => $textStyle, 4 => $moneyStyle]));
                }
            }

            $ordersSheet->setAutoFilter(new AutoFilter(0, 1, \count($orderHeaders) - 1, $ordersSheet->getWrittenRowCount()));
            $itemsSheet->setAutoFilter(new AutoFilter(0, 1, \count(self::ITEM_HEADERS) - 1, $itemsSheet->getWrittenRowCount()));
            $writer->setCurrentSheet($ordersSheet);
        } finally {
            $writer->close();
        }
    }

    /** Создаёт лист с заголовками, шириной столбцов и закреплённой первой строкой. */
    private function prepareSheet(Writer $writer, string $name, array $headers, bool $create = false): WriterSheet
    {
        $sheet = $create ? $writer->addNewSheetAndMakeItCurrent() : $writer->getCurrentSheet();
        $sheet->setName($name)->setSheetView(new SheetView(freezeRow: 2, freezeColumn: 'B'));
        $sheet->setColumnWidthForRange(20, 1, \count($headers));
        $headerStyle = new Style(fontName: 'Calibri', fontSize: 11, fontBold: true, fontColor: '254A40', backgroundColor: 'E8F0EB', shouldWrapText: true);
        $writer->addRow($this->safeRow($headers, $headerStyle)->withHeight(32));

        return $sheet;
    }

    /** Явно записывает строки как текст, чтобы символ «=» не превращался в формулу. */
    private function safeRow(array $values, Style $style, array $columnStyles = []): Row
    {
        $cells = [];

        foreach ($values as $index => $value) {
            $cellStyle = $columnStyles[$index] ?? $style;
            $cells[] = \is_string($value) ? new StringCell($value, $cellStyle) : Cell::fromValue($value, $cellStyle);
        }

        return new Row($cells);
    }
}
