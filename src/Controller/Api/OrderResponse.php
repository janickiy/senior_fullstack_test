<?php

namespace App\Controller\Api;

use App\Application\Order\Result\ImportResult;
use App\Application\Order\Result\OrderPage;
use App\Domain\Order\MarketplaceOrder;

final class OrderResponse
{
    public function page(OrderPage $page): array
    {
        return [
            'items' => array_map($this->order(...), $page->items),
            'pagination' => [
                'page' => $page->page,
                'limit' => $page->limit,
                'total' => $page->total,
                'pages' => (int) ceil($page->total / $page->limit),
            ],
        ];
    }

    public function order(MarketplaceOrder $order): array
    {
        return [
            'id' => $order->getId(),
            'marketplace_id' => $order->getMarketplaceId(),
            'status' => $order->getStatus()->value,
            'created_at' => $this->date($order->getCreatedAt()),
            'customer' => ['name' => $order->getCustomerName(), 'phone' => $order->getCustomerPhone()],
            'delivery' => [
                'type' => $order->getDeliveryType(),
                'district' => $order->getDistrict(),
                'address' => $order->getAddress(),
                'starts_at' => $this->date($order->getDeliveryStartsAt()),
                'ends_at' => $this->date($order->getDeliveryEndsAt()),
            ],
            'items' => array_map(fn (array $item): array => [
                'sku' => $item['sku'],
                'name' => $item['name'],
                'qty' => $item['qty'],
                'price' => $this->rubles($item['unit_price_cents']),
            ], $order->getItems()),
            'items_total' => $this->rubles($order->getItemsTotalCents()),
            'marketplace_total' => $this->rubles($order->getMarketplaceTotalCents()),
            'delivery_cost' => $this->rubles($order->getDeliveryCostCents()),
            'total' => $this->rubles($order->getGrandTotalCents()),
            'needs_review' => $order->needsReview(),
        ];
    }

    /** @param list<ImportResult> $results */
    public function batch(array $results): array
    {
        $summary = ['total' => count($results), 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'duplicate' => 0, 'rejected' => 0];
        foreach ($results as $result) {
            ++$summary[$result->outcome];
        }

        return ['summary' => $summary, 'results' => array_map($this->importResult(...), $results)];
    }

    private function importResult(ImportResult $result): array
    {
        $order = $result->saved?->order;

        return [
            'index' => $result->index,
            'marketplace_id' => $result->marketplaceId,
            'outcome' => $result->outcome,
            'code' => $result->code,
            'message' => $result->message,
            'order_id' => $order?->getId(),
            'status' => $order?->getStatus()->value,
            'needs_review' => $order?->needsReview(),
            'items_total' => null === $order ? null : $this->rubles($order->getItemsTotalCents()),
            'delivery_cost' => null === $order ? null : $this->rubles($order->getDeliveryCostCents()),
            'total' => null === $order ? null : $this->rubles($order->getGrandTotalCents()),
            'warnings' => $order?->needsReview() ? [[
                'code' => 'total_mismatch',
                'message' => 'Сумма позиций отличается от total маркетплейса: нужна проверка. Расчёт выполнен по позициям.',
            ]] : [],
            'errors' => $result->errors,
        ];
    }

    private function rubles(int $cents): int|float
    {
        return 0 === $cents % 100 ? intdiv($cents, 100) : round($cents / 100, 2);
    }

    private function date(?\DateTimeImmutable $date): ?string
    {
        return $date?->setTimezone(new \DateTimeZone('Europe/Moscow'))->format(\DateTimeInterface::ATOM);
    }
}
