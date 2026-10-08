<?php

namespace App\Enum;

enum OrderStatus: string
{
    case New = 'new';
    case Accepted = 'accepted';
    case Delivering = 'delivering';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    private const array MARKETPLACE_STATUSES = [
        'NEW' => self::New,
        'ACCEPTED' => self::Accepted,
        'IN_DELIVERY' => self::Delivering,
        'DONE' => self::Delivered,
        'CANCELED' => self::Cancelled,
    ];

    /** Преобразует статус маркетплейса в статус заказа. */
    public static function fromMarketplace(string $status): self
    {
        return self::MARKETPLACE_STATUSES[$status] ?? throw new \ValueError('Неизвестный статус маркетплейса: '.$status);
    }

    /** Возвращает допустимые статусы маркетплейса для валидации входных данных. */
    public static function marketplaceValues(): array
    {
        return array_keys(self::MARKETPLACE_STATUSES);
    }

    /** Возвращает статус маркетплейса для выгрузки заказа и повторного импорта. */
    public function toMarketplace(): string
    {
        return array_search($this, self::MARKETPLACE_STATUSES, true);
    }

    public function isFinal(): bool
    {
        return self::Delivered === $this || self::Cancelled === $this;
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
