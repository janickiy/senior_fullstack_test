<?php

namespace App\DTO\Order;

use App\DTO\DataTransferObject;
use App\Entity\MarketplaceOrder;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class OrderViewDto implements DataTransferObject
{
    public function __construct(
        public int $id,
        public string $marketplaceId,
        public string $status,
        public string $createdAt,
        public array $customer,
        public array $delivery,
        public array $items,
        public int $itemsTotalCents,
        public int $marketplaceTotalCents,
        public int $deliveryCostCents,
        public int $grandTotalCents,
        public bool $needsReview,
    ) {
    }

    public static function fromEntity(MarketplaceOrder $order): self
    {
        $timezone = new \DateTimeZone('Europe/Moscow');
        $format = static fn (?\DateTimeImmutable $time): ?string => $time?->setTimezone($timezone)->format(\DateTimeInterface::ATOM);

        return new self(
            $order->getId(),
            $order->getMarketplaceId(),
            $order->getStatus()->value,
            $format($order->getCreatedAt()),
            ['name' => $order->getCustomerName(), 'phone' => $order->getCustomerPhone()],
            [
                'type' => $order->getDeliveryType(),
                'district' => $order->getDistrict(),
                'address' => $order->getAddress(),
                'starts_at' => $format($order->getDeliveryStartsAt()),
                'ends_at' => $format($order->getDeliveryEndsAt()),
            ],
            array_map(static fn (array $item): array => [
                'sku' => $item['sku'], 'name' => $item['name'], 'qty' => $item['qty'],
                'price' => self::rubles($item['unit_price_cents']),
            ], $order->getItems()),
            $order->getItemsTotalCents(),
            $order->getMarketplaceTotalCents(),
            $order->getDeliveryCostCents(),
            $order->getGrandTotalCents(),
            $order->needsReview(),
        );
    }

    public static function rubles(int $cents): int|float
    {
        return 0 === $cents % 100 ? intdiv($cents, 100) : round($cents / 100, 2);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id, 'marketplace_id' => $this->marketplaceId, 'status' => $this->status,
            'created_at' => $this->createdAt, 'customer' => $this->customer, 'delivery' => $this->delivery,
            'items' => $this->items, 'items_total' => self::rubles($this->itemsTotalCents),
            'marketplace_total' => self::rubles($this->marketplaceTotalCents),
            'delivery_cost' => self::rubles($this->deliveryCostCents),
            'total' => self::rubles($this->grandTotalCents), 'needs_review' => $this->needsReview,
        ];
    }
}
