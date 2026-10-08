<?php

namespace App\DTO\Order;

use App\DTO\DataTransferObject;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class ImportBatchResultDto implements DataTransferObject
{
    /** @param list<ImportOrderResultDto> $results */
    public function __construct(public array $results)
    {
    }

    public function toArray(): array
    {
        $summary = ['total' => count($this->results), 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'duplicate' => 0, 'rejected' => 0];
        foreach ($this->results as $result) {
            ++$summary[$result->outcome];
        }

        return ['summary' => $summary, 'results' => array_map(static fn (ImportOrderResultDto $result): array => $result->toArray(), $this->results)];
    }
}
