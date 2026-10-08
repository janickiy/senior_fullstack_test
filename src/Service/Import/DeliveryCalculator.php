<?php

namespace App\Service\Import;

use App\Exception\InvalidOrderException;

/**
 * Рассчитывает стоимость доставки в копейках по тарифу района и сумме товаров.
 * Учитывает порог бесплатной доставки и доплату за срочность; для самовывоза
 * возвращает нулевую стоимость, а для неизвестного района сообщает об ошибке.
 */
final class DeliveryCalculator
{
    private const array TARIFFS = [
        'centre' => ['base' => 30_000, 'free_from' => 500_000],
        'north' => ['base' => 40_000, 'free_from' => 700_000],
        'south' => ['base' => 40_000, 'free_from' => 700_000],
        'suburb' => ['base' => 70_000, 'free_from' => null],
    ];

    public function calculate(
        string $type,
        ?string $district,
        int $itemsTotalCents,
        \DateTimeImmutable $createdAt,
        ?\DateTimeImmutable $startsAt,
    ): int {
        if ('pickup' === $type) {
            return 0;
        }

        if ('delivery' !== $type) {
            throw new InvalidOrderException('invalid_delivery_type', 'Неизвестный способ получения заказа.');
        }

        $tariff = self::TARIFFS[$district ?? ''] ?? throw new InvalidOrderException(
            'unknown_district',
            'Для района доставки не настроен тариф.',
            [['field' => 'delivery.district', 'message' => 'Неизвестный район доставки.']],
        );

        if (null === $startsAt) {
            throw new InvalidOrderException('invalid_delivery_interval', 'Не указан интервал доставки.');
        }

        $base = null !== $tariff['free_from'] && $itemsTotalCents >= $tariff['free_from'] ? 0 : $tariff['base'];
        $urgency = $startsAt->getTimestamp() - $createdAt->getTimestamp() < 7200 ? 20_000 : 0;

        return $base + $urgency;
    }
}
