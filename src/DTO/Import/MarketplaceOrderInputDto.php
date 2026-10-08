<?php

namespace App\DTO\Import;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Validator\Constraints as Assert;

#[Exclude]
final readonly class MarketplaceOrderInputDto
{
    public function __construct(
        #[Assert\Sequentially([
            new Assert\NotBlank(message: 'Укажите идентификатор заказа.'),
            new Assert\Type(type: ['string', 'int'], message: 'Идентификатор должен быть строкой или целым числом.'),
            new Assert\Length(max: 255, maxMessage: 'Идентификатор не должен превышать 255 символов.'),
        ])]
        public mixed $marketplaceId,
        #[Assert\Sequentially([
            new Assert\NotBlank(message: 'Укажите статус заказа.'),
            new Assert\Type(type: 'string', message: 'Статус должен быть строкой.'),
            new Assert\Choice(choices: ['NEW', 'ACCEPTED', 'IN_DELIVERY', 'DONE', 'CANCELED'], message: 'Неизвестный статус маркетплейса.'),
        ])]
        public mixed $status,
        #[Assert\Sequentially([
            new Assert\NotBlank(message: 'Укажите время создания заказа.'),
            new Assert\Type(type: 'string', message: 'Время создания должно быть строкой.'),
            new Assert\Regex(pattern: '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-](?:0\d|1\d|2[0-3]):[0-5]\d)$/D', message: 'Время создания должно содержать дату, время и часовой пояс.'),
            new Assert\DateTime(format: \DateTimeInterface::RFC3339, message: 'Некорректная дата или время создания.'),
        ])]
        public mixed $createdAt,
        #[Assert\Sequentially([
            new Assert\NotBlank(message: 'Укажите имя покупателя.'),
            new Assert\Type(type: 'string', message: 'Имя покупателя должно быть строкой.'),
            new Assert\Length(max: 255, maxMessage: 'Имя покупателя не должно превышать 255 символов.'),
        ])]
        public mixed $customerName,
        #[Assert\Sequentially([
            new Assert\NotBlank(message: 'Укажите телефон покупателя.'),
            new Assert\Type(type: 'string', message: 'Телефон покупателя должен быть строкой.'),
            new Assert\Regex(pattern: '/^\+?[0-9\s()\-]+$/uD', message: 'Телефон содержит недопустимые символы.'),
        ])]
        public mixed $customerPhone,
        #[Assert\Sequentially([
            new Assert\NotBlank(message: 'Укажите способ получения заказа.'),
            new Assert\Type(type: 'string', message: 'Способ получения должен быть строкой.'),
            new Assert\Choice(choices: ['delivery', 'pickup'], message: 'Допустимы только доставка или самовывоз.'),
        ])]
        public mixed $deliveryType,
        #[Assert\Sequentially([
            new Assert\NotNull(message: 'Укажите позиции заказа.'),
            new Assert\Type(type: 'array', message: 'Позиции должны быть массивом.'),
            new Assert\Count(min: 1, minMessage: 'В заказе должна быть хотя бы одна позиция.'),
        ])]
        public mixed $items,
        #[Assert\Sequentially([
            new Assert\NotNull(message: 'Укажите сумму маркетплейса.'),
            new Assert\Type(type: ['string', 'int', 'float'], message: 'Сумма должна быть числом или десятичной строкой.'),
        ])]
        public mixed $marketplaceTotal,
    ) {
    }
}
