<?php

namespace App\DTO\Order;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class PersistedOrderResultDto
{
    public function __construct(
        public string $outcome,
        public string $code,
        public string $message,
        public int $orderId,
        public string $status,
        public int $itemsTotalCents,
        public int $deliveryCostCents,
        public int $grandTotalCents,
        public bool $needsReview,
    ) {
    }
}
