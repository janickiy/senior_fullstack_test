<?php

namespace App\Application\Order\Port;

use App\Application\Order\OrderCriteria;
use App\Application\Order\Result\OrderPage;
use App\Domain\Order\MarketplaceOrder;

interface OrderRepositoryInterface
{
    /** Читает актуальный заказ магазина с блокировкой до конца текущей транзакции. */
    public function findForImport(string $shopId, string $marketplaceId): ?MarketplaceOrder;

    /** Регистрирует новый или изменённый заказ в текущей транзакции; фиксацию выполняет TransactionInterface. */
    public function save(MarketplaceOrder $order): void;

    /** Возвращает страницу заказов магазина, отсортированную от новых к старым. */
    public function findPageForShop(string $shopId, OrderCriteria $criteria): OrderPage;
}
