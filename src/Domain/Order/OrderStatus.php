<?php

namespace App\Domain\Order;

enum OrderStatus: string
{
    case New = 'new';
    case Accepted = 'accepted';
    case Delivering = 'delivering';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function isFinal(): bool
    {
        return self::Delivered === $this || self::Cancelled === $this;
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
