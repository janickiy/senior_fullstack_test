<?php

namespace App\Service;

use App\DTO\Order\ImportBatchResultDto;
use App\DTO\Order\ImportOrderResultDto;
use App\DTO\Order\ImportOrdersRequestDto;
use App\Exception\InvalidOrderException;
use App\Repository\OrderRepositoryInterface;
use App\Service\Import\OrderNormalizer;
use Doctrine\DBAL\Exception;
use Psr\Log\LoggerInterface;

/**
 * Импортирует пачку заказов маркетплейса для указанного магазина.
 * Выявляет дубли, передаёт заказы на проверку и сохранение, собирает результат
 * по каждой записи и продолжает обработку при ошибке отдельного заказа.
 */
final class OrderImportService
{
    public function __construct(
        private readonly OrderNormalizer $normalizer,
        private readonly OrderRepositoryInterface $orders,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param string $shopId
     * @param ImportOrdersRequestDto $request
     * @return ImportBatchResultDto
     */
    public function import(string $shopId, ImportOrdersRequestDto $request): ImportBatchResultDto
    {
        $seen = [];
        $results = [];
        foreach ($request->orders as $index => $rawOrder) {
            $marketplaceId = $this->extractId($rawOrder);
            if (null !== $marketplaceId) {
                if (isset($seen[$marketplaceId])) {
                    $results[] = new ImportOrderResultDto($index, $marketplaceId, 'duplicate', 'duplicate_in_batch', 'Повтор идентификатора в пачке: обработано первое вхождение.');
                    continue;
                }
                $seen[$marketplaceId] = true;
            }
            try {
                $order = $this->normalizer->normalize($rawOrder);
                $results[] = ImportOrderResultDto::fromSaved($index, $order->marketplaceId, $this->orders->import($shopId, $order));
            } catch (InvalidOrderException $exception) {
                $results[] = new ImportOrderResultDto($index, $marketplaceId, 'rejected', $exception->reasonCode, $exception->getMessage(), errors: $exception->details);
            } catch (Exception $exception) {
                $this->logger->error('Failed to persist imported order.', ['shop_id' => $shopId, 'marketplace_id' => $marketplaceId, 'exception' => $exception]);
                $results[] = new ImportOrderResultDto($index, $marketplaceId, 'rejected', 'persistence_error', 'Не удалось сохранить заказ. Попробуйте повторить импорт.');
            }
        }

        return new ImportBatchResultDto($results);
    }

    private function extractId(mixed $rawOrder): ?string
    {
        if (!is_array($rawOrder) || !isset($rawOrder['id']) || (!is_string($rawOrder['id']) && !is_int($rawOrder['id']))) {
            return null;
        }
        $id = trim((string) $rawOrder['id']);

        return '' === $id ? null : $id;
    }
}
