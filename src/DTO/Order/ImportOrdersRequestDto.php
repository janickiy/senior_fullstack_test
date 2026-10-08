<?php

namespace App\DTO\Order;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Validator\Constraints as Assert;

#[Exclude]
final readonly class ImportOrdersRequestDto
{
    public function __construct(
        #[Assert\Count(min: 1, minMessage: 'Пачка должна содержать хотя бы один заказ.')]
        public array $orders,
    ) {
    }
}
