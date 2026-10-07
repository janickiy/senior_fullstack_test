<?php

namespace App\DTO\Order;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[Exclude]
final readonly class ImportOrdersRequestDto
{
    public function __construct(
        #[Assert\Count(min: 1, minMessage: 'Пачка должна содержать хотя бы один заказ.')]
        public array $orders,
    ) {
    }

    #[Assert\Callback]
    public function validateList(ExecutionContextInterface $context): void
    {
        if (!array_is_list($this->orders)) {
            $context->buildViolation('orders должен быть JSON-массивом заказов.')->atPath('orders')->addViolation();
        }
    }
}
