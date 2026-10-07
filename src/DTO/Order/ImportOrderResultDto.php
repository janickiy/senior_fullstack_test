<?php

namespace App\DTO\Order;

use App\DTO\DataTransferObject;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class ImportOrderResultDto implements DataTransferObject
{
    public function __construct(
        public int $index,
        public ?string $marketplaceId,
        public string $outcome,
        public string $code,
        public string $message,
        public ?PersistedOrderResultDto $saved = null,
        public array $errors = [],
    ) {
    }

    public static function fromSaved(int $index, string $marketplaceId, PersistedOrderResultDto $saved): self
    {
        return new self($index, $marketplaceId, $saved->outcome, $saved->code, $saved->message, $saved);
    }

    public function toArray(): array
    {
        return [
            'index' => $this->index, 'marketplace_id' => $this->marketplaceId,
            'outcome' => $this->outcome, 'code' => $this->code, 'message' => $this->message,
            'order_id' => $this->saved?->orderId, 'status' => $this->saved?->status,
            'needs_review' => $this->saved?->needsReview,
            'items_total' => null === $this->saved ? null : OrderViewDto::rubles($this->saved->itemsTotalCents),
            'delivery_cost' => null === $this->saved ? null : OrderViewDto::rubles($this->saved->deliveryCostCents),
            'total' => null === $this->saved ? null : OrderViewDto::rubles($this->saved->grandTotalCents),
            'warnings' => $this->saved?->needsReview ? [[
                'code' => 'total_mismatch',
                'message' => 'Сумма позиций отличается от total маркетплейса: нужна проверка. Расчёт выполнен по позициям.',
            ]] : [],
            'errors' => $this->errors,
        ];
    }
}
