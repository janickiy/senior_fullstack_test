<?php

namespace App\Application\Order\Result;

use App\Domain\Order\MarketplaceOrder;

final readonly class OrderPage
{
    /** @param list<MarketplaceOrder> $items */
    public function __construct(public array $items, public int $page, public int $limit, public int $total)
    {
    }
}
