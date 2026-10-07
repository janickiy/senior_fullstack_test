<?php

namespace App\DTO\Import;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class NormalizedOrderDto
{
    /** @param list<array{sku: string, name: string, qty: int, unit_price_cents: int}> $items */
    public function __construct(
        public string $marketplaceId,
        public string $status,
        public \DateTimeImmutable $createdAt,
        public string $customerName,
        public string $customerPhone,
        public string $deliveryType,
        public ?string $district,
        public ?string $address,
        public ?\DateTimeImmutable $deliveryStartsAt,
        public ?\DateTimeImmutable $deliveryEndsAt,
        public array $items,
        public int $itemsTotalCents,
        public int $marketplaceTotalCents,
        public bool $needsReview,
        public int $deliveryCostCents,
        public int $grandTotalCents,
    ) {
    }
}
