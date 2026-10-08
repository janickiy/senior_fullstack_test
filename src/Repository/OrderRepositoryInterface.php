<?php

namespace App\Repository;

use App\DTO\Import\NormalizedOrderDto;
use App\DTO\Order\OrderListQueryDto;
use App\DTO\Order\OrderPageDto;
use App\DTO\Order\PersistedOrderResultDto;

interface OrderRepositoryInterface
{
    /**
     * Сохраняет нормализованный заказ указанного магазина или обновляет только статус
     * существующего заказа с тем же идентификатором маркетплейса, сохраняя финальный статус.
     * Возвращает результат импорта и фактически сохранённые данные заказа.
     */
    public function import(string $shopId, NormalizedOrderDto $order): PersistedOrderResultDto;

    /**
     * Возвращает страницу заказов только указанного магазина с необязательным фильтром
     * по статусу, сортировкой от новых к старым и общим количеством подходящих заказов.
     * Номер страницы и её размер берёт из параметров запроса.
     */
    public function findPageForShop(string $shopId, OrderListQueryDto $query): OrderPageDto;
}
