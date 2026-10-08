<?php

namespace App\DTO\Import;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Validator\Constraints as Assert;

#[Exclude]
final readonly class OrderItemInputDto
{
    public function __construct(
        #[Assert\Sequentially([
            new Assert\NotNull(message: 'Укажите количество позиции.'),
            new Assert\Type(type: 'int', message: 'Количество должно быть целым числом.'),
            new Assert\Positive(message: 'Количество должно быть больше нуля.'),
        ])]
        public mixed $quantity,
        #[Assert\Sequentially([
            new Assert\NotNull(message: 'Укажите цену позиции.'),
            new Assert\Type(type: ['string', 'int', 'float'], message: 'Цена должна быть числом или десятичной строкой.'),
        ])]
        public mixed $price,
        #[Assert\Sequentially([
            new Assert\Type(type: 'string', message: 'Артикул позиции должен быть строкой.'),
            new Assert\Length(max: 255, maxMessage: 'Артикул позиции не должен превышать 255 символов.'),
        ])]
        public mixed $sku = '',
        #[Assert\Sequentially([
            new Assert\Type(type: 'string', message: 'Название позиции должно быть строкой.'),
            new Assert\Length(max: 255, maxMessage: 'Название позиции не должно превышать 255 символов.'),
        ])]
        public mixed $name = '',
    ) {
    }
}
