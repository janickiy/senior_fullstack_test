<?php

namespace App\DTO\Order;

use App\DTO\DataTransferObject;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class OrderPageDto implements DataTransferObject
{
    /** @param list<OrderViewDto> $items */
    public function __construct(public array $items, public int $page, public int $limit, public int $total)
    {
    }

    public function toArray(): array
    {
        return [
            'items' => array_map(static fn (OrderViewDto $item): array => $item->toArray(), $this->items),
            'pagination' => ['page' => $this->page, 'limit' => $this->limit, 'total' => $this->total, 'pages' => (int) ceil($this->total / $this->limit)],
        ];
    }
}
