<?php

namespace App\Service\Import;

use App\DTO\Import\DeliveryInputDto;
use App\DTO\Import\MarketplaceOrderInputDto;
use App\DTO\Import\NormalizedOrderDto;
use App\DTO\Import\OrderItemInputDto;
use App\Enum\OrderStatus;
use App\Exception\InvalidOrderException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Проверяет данные заказа маркетплейса и подготавливает их к сохранению.
 * Приводит телефон, статус, даты и денежные суммы к внутреннему формату,
 * рассчитывает суммы товаров и доставки, отмечает расхождение с суммой
 * маркетплейса и возвращает нормализованный DTO заказа.
 */
final class OrderNormalizer
{
    private const array ORDER_FIELDS = [
        'marketplaceId' => ['id', 'invalid_id'],
        'status' => ['status', 'unknown_status'],
        'createdAt' => ['created_at', 'invalid_created_at'],
        'customerName' => ['customer.name', 'invalid_customer_name'],
        'customerPhone' => ['customer.phone', 'invalid_phone'],
        'deliveryType' => ['delivery.type', 'invalid_delivery_type'],
        'items' => ['items', 'invalid_items'],
        'marketplaceTotal' => ['total', 'invalid_total'],
    ];

    public function __construct(
        private readonly ValidatorInterface $validator,
        private readonly DeliveryCalculator $deliveryCalculator,
    ) {
    }

    public function normalize(mixed $raw): NormalizedOrderDto
    {
        if (!\is_array($raw)) {
            throw new InvalidOrderException('invalid_order', 'Заказ должен быть объектом.');
        }

        $customer = \is_array($raw['customer'] ?? null) ? $raw['customer'] : [];
        $delivery = \is_array($raw['delivery'] ?? null) ? $raw['delivery'] : [];
        $input = new MarketplaceOrderInputDto(
            marketplaceId: $this->trim($raw['id'] ?? null),
            status: $this->trim($raw['status'] ?? null),
            createdAt: $this->trim($raw['created_at'] ?? null),
            customerName: $this->trim($customer['name'] ?? null),
            customerPhone: $this->trim($customer['phone'] ?? null),
            deliveryType: $this->trim($delivery['type'] ?? null),
            items: $raw['items'] ?? null,
            marketplaceTotal: $raw['total'] ?? null,
        );
        $this->validate($input, self::ORDER_FIELDS);

        $customerPhone = $this->normalizePhone($input->customerPhone);

        $createdAt = new \DateTimeImmutable($input->createdAt);
        $district = $address = $startsAt = $endsAt = null;

        if ('delivery' === $input->deliveryType) {
            $deliveryInput = new DeliveryInputDto(
                district: $this->trim($delivery['district'] ?? null),
                address: $this->trim($delivery['address'] ?? null),
                date: $this->trim($delivery['date'] ?? null),
                timeFrom: $this->trim($delivery['time_from'] ?? null),
                timeTo: $this->trim($delivery['time_to'] ?? null),
            );
            $this->validate($deliveryInput, [
                'district' => ['delivery.district', 'invalid_delivery'],
                'address' => ['delivery.address', 'invalid_delivery'],
                'date' => ['delivery.date', 'invalid_delivery_interval'],
                'timeFrom' => ['delivery.time_from', 'invalid_delivery_interval'],
                'timeTo' => ['delivery.time_to', 'invalid_delivery_interval'],
            ]);
            $district = $deliveryInput->district;
            $address = $deliveryInput->address;
            $timezone = new \DateTimeZone('Europe/Moscow');
            $startsAt = new \DateTimeImmutable($deliveryInput->date.' '.$deliveryInput->timeFrom, $timezone);
            $endsAt = new \DateTimeImmutable($deliveryInput->date.' '.$deliveryInput->timeTo, $timezone);

            if ($endsAt <= $startsAt) {
                throw new InvalidOrderException('invalid_delivery_interval', 'Конец интервала доставки должен быть позже начала.', [
                    ['field' => 'delivery.time_to', 'message' => 'Конец интервала должен быть позже начала в тот же день.'],
                ]);
            }
        }

        [$items, $itemsTotalCents] = $this->normalizeItems($input->items);
        $marketplaceTotalCents = $this->moneyToCents($input->marketplaceTotal, 'invalid_total', 'total');
        $deliveryCostCents = $this->deliveryCalculator->calculate($input->deliveryType, $district, $itemsTotalCents, $createdAt, $startsAt);

        if ($itemsTotalCents > \PHP_INT_MAX - $deliveryCostCents) {
            throw new InvalidOrderException('amount_too_large', 'Итоговая сумма заказа слишком велика.');
        }

        return new NormalizedOrderDto(
            marketplaceId: (string) $input->marketplaceId,
            status: OrderStatus::fromMarketplace($input->status)->value,
            createdAt: $createdAt,
            customerName: $input->customerName,
            customerPhone: $customerPhone,
            deliveryType: $input->deliveryType,
            district: $district,
            address: $address,
            deliveryStartsAt: $startsAt,
            deliveryEndsAt: $endsAt,
            items: $items,
            itemsTotalCents: $itemsTotalCents,
            marketplaceTotalCents: $marketplaceTotalCents,
            needsReview: $itemsTotalCents !== $marketplaceTotalCents,
            deliveryCostCents: $deliveryCostCents,
            grandTotalCents: $itemsTotalCents + $deliveryCostCents,
        );
    }

    /** Проверяет телефон и приводит его к формату +7XXXXXXXXXX. */
    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/[\s()\-]/u', '', $phone);
        $phone = ltrim($phone, '+');

        if (!preg_match('/^[78][0-9]{10}$/D', $phone)) {
            throw new InvalidOrderException('invalid_phone', 'Телефон должен содержать 11 цифр и начинаться с 7 или 8.', [
                ['field' => 'customer.phone', 'message' => 'Не удалось привести телефон к формату +7XXXXXXXXXX.'],
            ]);
        }

        return '+7'.substr($phone, 1);
    }

    /**
     * Проверяет позиции и рассчитывает их общую стоимость в копейках.
     *
     * @return array{list<array{sku: string, name: string, qty: int, unit_price_cents: int}>, int}
     */
    private function normalizeItems(array $rawItems): array
    {
        if (!array_is_list($rawItems)) {
            throw new InvalidOrderException('invalid_items', 'Позиции заказа должны быть списком.');
        }

        $items = [];
        $itemsTotalCents = 0;

        foreach ($rawItems as $index => $rawItem) {
            if (!\is_array($rawItem)) {
                throw new InvalidOrderException('invalid_items', 'Каждая позиция заказа должна быть объектом.', [
                    ['field' => 'items.'.$index, 'message' => 'Некорректная позиция заказа.'],
                ]);
            }

            $item = new OrderItemInputDto(
                quantity: $rawItem['qty'] ?? null,
                price: $rawItem['price'] ?? null,
                sku: $this->trim($rawItem['sku'] ?? ''),
                name: $this->trim($rawItem['name'] ?? ''),
            );
            $this->validate($item, [
                'quantity' => ['items.'.$index.'.qty', 'invalid_quantity'],
                'price' => ['items.'.$index.'.price', 'invalid_price'],
                'sku' => ['items.'.$index.'.sku', 'invalid_items'],
                'name' => ['items.'.$index.'.name', 'invalid_items'],
            ]);
            $unitPriceCents = $this->moneyToCents($item->price, 'invalid_price', 'items.'.$index.'.price');

            if ($unitPriceCents > intdiv(\PHP_INT_MAX, $item->quantity)) {
                throw new InvalidOrderException('amount_too_large', 'Сумма позиции слишком велика.');
            }

            $lineTotalCents = $unitPriceCents * $item->quantity;

            if ($itemsTotalCents > \PHP_INT_MAX - $lineTotalCents) {
                throw new InvalidOrderException('amount_too_large', 'Сумма заказа слишком велика.');
            }

            $itemsTotalCents += $lineTotalCents;
            $items[] = [
                'sku' => $item->sku,
                'name' => $item->name,
                'qty' => $item->quantity,
                'unit_price_cents' => $unitPriceCents,
            ];
        }

        return [$items, $itemsTotalCents];
    }

    private function trim(mixed $value): mixed
    {
        return \is_string($value) ? trim($value) : $value;
    }

    /** @param array<string, array{string, string}> $fields */
    private function validate(object $input, array $fields): void
    {
        $violations = $this->validator->validate($input);

        if (0 === \count($violations)) {
            return;
        }

        $details = [];
        $first = $violations[0];
        $reasonCode = $fields[$first->getPropertyPath()][1] ?? 'invalid_order';

        foreach ($violations as $violation) {
            $details[] = [
                'field' => $fields[$violation->getPropertyPath()][0] ?? $violation->getPropertyPath(),
                'message' => (string) $violation->getMessage(),
            ];
        }

        throw new InvalidOrderException($reasonCode, (string) $first->getMessage(), $details);
    }

    /**
     * @param int|float|string $value
     * @param string $reasonCode
     * @param string $field
     * @return int
     * @throws \JsonException
     */
    private function moneyToCents(int|float|string $value, string $reasonCode, string $field): int
    {
        if (\is_float($value)) {
            $decimal = is_finite($value) ? json_encode($value, \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR) : '';
        } else {
            $decimal = (string) $value;
        }

        if (!preg_match('/^([0-9]+)(?:\.([0-9]{1,2}))?$/D', $decimal, $parts)) {
            throw new InvalidOrderException($reasonCode, 'Денежная сумма должна быть неотрицательной и содержать не более двух знаков после точки.', [
                ['field' => $field, 'message' => 'Ожидается сумма в рублях с точностью до копейки.'],
            ]);
        }

        $cents = ltrim($parts[1].str_pad($parts[2] ?? '', 2, '0'), '0');
        $cents = '' === $cents ? '0' : $cents;
        $maximum = (string) \PHP_INT_MAX;

        if (\strlen($cents) > \strlen($maximum) || (\strlen($cents) === \strlen($maximum) && strcmp($cents, $maximum) > 0)) {
            throw new InvalidOrderException('amount_too_large', 'Денежная сумма слишком велика.', [
                ['field' => $field, 'message' => 'Сумма превышает поддерживаемый предел.'],
            ]);
        }

        return (int) $cents;
    }
}
