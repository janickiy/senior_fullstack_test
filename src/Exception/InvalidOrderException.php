<?php

namespace App\Exception;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class InvalidOrderException extends \RuntimeException
{
    /** @param list<array{field: string, message: string}> $details */
    public function __construct(
        public readonly string $reasonCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
