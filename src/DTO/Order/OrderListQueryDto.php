<?php

namespace App\DTO\Order;

use App\Enum\OrderStatus;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Validator\Constraints as Assert;

#[Exclude]
final readonly class OrderListQueryDto
{
    public function __construct(
        #[Assert\Choice(callback: [OrderStatus::class, 'values'], message: 'Неизвестный статус заказа.')]
        public ?string $status = null,
        #[Assert\Positive(message: 'Номер страницы должен быть больше нуля.')]
        public int $page = 1,
        #[Assert\Range(min: 1, max: 100, notInRangeMessage: 'Размер страницы должен быть от 1 до 100.')]
        public int $limit = 20,
    ) {
    }
}
