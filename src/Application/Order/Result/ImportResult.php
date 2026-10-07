<?php

namespace App\Application\Order\Result;

final readonly class ImportResult
{
    /** @param list<array{field: string, message: string}> $errors */
    public function __construct(
        public int $index,
        public ?string $marketplaceId,
        public string $outcome,
        public string $code,
        public string $message,
        public ?ImportedOrder $saved = null,
        public array $errors = [],
    ) {
    }

    public static function fromSaved(int $index, ImportedOrder $saved): self
    {
        return new self($index, $saved->order->getMarketplaceId(), $saved->outcome, $saved->code, $saved->message, $saved);
    }
}
