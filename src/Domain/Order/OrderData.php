<?php

namespace App\Domain\Order;

final readonly class OrderData
{
    public int $itemsTotalCents;
    public int $deliveryCostCents;
    public int $grandTotalCents;
    public bool $needsReview;

    /** @param list<array{sku: string, name: string, qty: int, unit_price_cents: int}> $items */
    public function __construct(
        public string $marketplaceId,
        public OrderStatus $status,
        public \DateTimeImmutable $createdAt,
        public string $customerName,
        public string $customerPhone,
        public string $deliveryType,
        public ?string $district,
        public ?string $address,
        public ?\DateTimeImmutable $deliveryStartsAt,
        public ?\DateTimeImmutable $deliveryEndsAt,
        public array $items,
        public int $marketplaceTotalCents,
        DeliveryCalculator $deliveryCalculator = new DeliveryCalculator(),
    ) {
        if ('delivery' === $deliveryType && (null === $deliveryStartsAt || null === $deliveryEndsAt || $deliveryEndsAt <= $deliveryStartsAt)) {
            throw new InvalidOrderException('invalid_delivery_interval', 'Конец интервала доставки должен быть позже начала.', [
                ['field' => 'delivery.time_to', 'message' => 'Конец интервала должен быть позже начала в тот же день.'],
            ]);
        }

        $this->itemsTotalCents = $this->sumItems($items);
        $this->deliveryCostCents = $deliveryCalculator->calculate($deliveryType, $district, $this->itemsTotalCents, $createdAt, $deliveryStartsAt);
        if ($this->itemsTotalCents > \PHP_INT_MAX - $this->deliveryCostCents) {
            throw new InvalidOrderException('amount_too_large', 'Итоговая сумма заказа слишком велика.');
        }
        $this->grandTotalCents = $this->itemsTotalCents + $this->deliveryCostCents;
        $this->needsReview = $this->itemsTotalCents !== $marketplaceTotalCents;
    }

    private function sumItems(array $items): int
    {
        if ([] === $items) {
            throw new InvalidOrderException('invalid_items', 'В заказе должна быть хотя бы одна позиция.');
        }
        $total = 0;
        foreach ($items as $item) {
            if ($item['qty'] < 1 || $item['unit_price_cents'] < 0) {
                throw new InvalidOrderException('invalid_items', 'Количество должно быть положительным, а цена — неотрицательной.');
            }
            if ($item['unit_price_cents'] > intdiv(\PHP_INT_MAX, $item['qty'])) {
                throw new InvalidOrderException('amount_too_large', 'Сумма позиции слишком велика.');
            }
            $lineTotal = $item['unit_price_cents'] * $item['qty'];
            if ($total > \PHP_INT_MAX - $lineTotal) {
                throw new InvalidOrderException('amount_too_large', 'Сумма заказа слишком велика.');
            }
            $total += $lineTotal;
        }

        return $total;
    }
}
