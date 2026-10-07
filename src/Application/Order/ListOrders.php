<?php

namespace App\Application\Order;

use App\Application\Order\Port\OrderRepositoryInterface;
use App\Application\Order\Result\OrderPage;

final class ListOrders
{
    public function __construct(private readonly OrderRepositoryInterface $orders)
    {
    }

    public function list(string $shopId, OrderCriteria $criteria): OrderPage
    {
        return $this->orders->findPageForShop($shopId, $criteria);
    }
}
