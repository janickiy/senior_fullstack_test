<?php

namespace App\DTO\Import;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Validator\Constraints as Assert;

#[Exclude]
final readonly class DeliveryInputDto
{
    public function __construct(
        #[Assert\Sequentially([
            new Assert\NotBlank(message: 'Укажите район доставки.'),
            new Assert\Type(type: 'string', message: 'Район доставки должен быть строкой.'),
        ])]
        public mixed $district,
        #[Assert\Sequentially([
            new Assert\NotBlank(message: 'Укажите адрес доставки.'),
            new Assert\Type(type: 'string', message: 'Адрес доставки должен быть строкой.'),
            new Assert\Length(max: 1000, maxMessage: 'Адрес доставки не должен превышать 1000 символов.'),
        ])]
        public mixed $address,
        #[Assert\Sequentially([
            new Assert\NotBlank(message: 'Укажите дату доставки.'),
            new Assert\Type(type: 'string', message: 'Дата доставки должна быть строкой.'),
            new Assert\Date(message: 'Некорректная дата доставки; используйте ГГГГ-ММ-ДД.'),
        ])]
        public mixed $date,
        #[Assert\Sequentially([
            new Assert\NotBlank(message: 'Укажите начало интервала доставки.'),
            new Assert\Type(type: 'string', message: 'Начало интервала должно быть строкой.'),
            new Assert\Regex(pattern: '/^(?:0\d|1\d|2[0-3]):[0-5]\d$/D', message: 'Начало интервала должно быть временем в формате ЧЧ:ММ.'),
        ])]
        public mixed $timeFrom,
        #[Assert\Sequentially([
            new Assert\NotBlank(message: 'Укажите конец интервала доставки.'),
            new Assert\Type(type: 'string', message: 'Конец интервала должен быть строкой.'),
            new Assert\Regex(pattern: '/^(?:0\d|1\d|2[0-3]):[0-5]\d$/D', message: 'Конец интервала должен быть временем в формате ЧЧ:ММ.'),
        ])]
        public mixed $timeTo,
    ) {
    }
}
