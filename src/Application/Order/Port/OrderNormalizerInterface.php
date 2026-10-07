<?php

namespace App\Application\Order\Port;

use App\Domain\Order\OrderData;

interface OrderNormalizerInterface
{
    /** Извлекает идентификатор даже из невалидного заказа для поиска дублей в пачке. */
    public function extractId(mixed $rawOrder): ?string;

    /** Проверяет входные данные маркетплейса и возвращает подготовленный заказ. */
    public function normalize(mixed $raw): OrderData;
}
