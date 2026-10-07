<?php

namespace App\Application\Order;

use App\Application\Order\Port\OrderRepositoryInterface;
use App\Application\Order\Port\TransactionInterface;
use App\Application\Order\Result\ImportedOrder;
use App\Domain\Order\MarketplaceOrder;
use App\Domain\Order\OrderData;

final class ImportOrder
{
    public function __construct(
        private readonly OrderRepositoryInterface $orders,
        private readonly TransactionInterface $transaction,
    ) {
    }

    public function import(string $shopId, OrderData $data): ImportedOrder
    {
        return $this->transaction->run(function () use ($shopId, $data): ImportedOrder {
            $order = $this->orders->findForImport($shopId, $data->marketplaceId);
            if (null === $order) {
                $order = new MarketplaceOrder($shopId, $data);
                $this->orders->save($order);

                return new ImportedOrder($order, 'created', 'created', 'Заказ создан.');
            }

            if ($order->applyImportedStatus($data->status)) {
                $this->orders->save($order);

                return new ImportedOrder($order, 'updated', 'status_updated', 'Обновлён только статус заказа.');
            }

            return $order->getStatus() !== $data->status
                ? new ImportedOrder($order, 'unchanged', 'final_status_locked', 'Финальный статус сохранён; изменение отклонено.')
                : new ImportedOrder($order, 'unchanged', 'no_changes', 'Статус не изменился; остальные данные сохранены без изменений.');
        });
    }
}
