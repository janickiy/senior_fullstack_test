<?php

namespace App\Application\Order;

use App\Application\Order\Port\OrderNormalizerInterface;
use App\Application\Order\Result\ImportResult;
use App\Domain\Order\InvalidOrderException;

final class ImportOrders
{
    public function __construct(
        private readonly OrderNormalizerInterface $normalizer,
        private readonly ImportOrder $importer,
    ) {
    }

    /**
     * @param list<mixed> $orders
     * @return list<ImportResult>
     */
    public function import(string $shopId, array $orders): array
    {
        $seen = [];
        $results = [];
        foreach ($orders as $index => $rawOrder) {
            $marketplaceId = $this->normalizer->extractId($rawOrder);
            if (null !== $marketplaceId && isset($seen[$marketplaceId])) {
                $results[] = new ImportResult($index, $marketplaceId, 'duplicate', 'duplicate_in_batch', 'Повтор идентификатора в пачке: обработано первое вхождение.');
                continue;
            }
            if (null !== $marketplaceId) {
                $seen[$marketplaceId] = true;
            }

            try {
                $order = $this->normalizer->normalize($rawOrder);
                $results[] = ImportResult::fromSaved($index, $this->importer->import($shopId, $order));
            } catch (InvalidOrderException $exception) {
                $results[] = new ImportResult($index, $marketplaceId, 'rejected', $exception->reasonCode, $exception->getMessage(), errors: $exception->details);
            } catch (PersistenceException) {
                $results[] = new ImportResult($index, $marketplaceId, 'rejected', 'persistence_error', 'Не удалось сохранить заказ. Попробуйте повторить импорт.');
            }
        }

        return $results;
    }

}
