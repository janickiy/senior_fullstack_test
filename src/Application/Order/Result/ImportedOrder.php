<?php

namespace App\Application\Order\Result;

use App\Domain\Order\MarketplaceOrder;

final readonly class ImportedOrder
{
    public function __construct(
        public MarketplaceOrder $order,
        public string $outcome,
        public string $code,
        public string $message,
    ) {
    }
}
