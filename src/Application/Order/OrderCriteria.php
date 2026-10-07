<?php

namespace App\Application\Order;

use App\Domain\Order\OrderStatus;

final readonly class OrderCriteria
{
    public function __construct(
        public ?OrderStatus $status = null,
        public int $page = 1,
        public int $limit = 20,
    ) {
        if ($page < 1 || $limit < 1 || $limit > 100 || $page > intdiv(PHP_INT_MAX, $limit)) {
            throw new \InvalidArgumentException('Invalid pagination.');
        }
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->limit;
    }
}
