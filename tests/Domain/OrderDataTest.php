<?php

namespace App\Tests\Domain;

use App\Domain\Order\InvalidOrderException;
use App\Domain\Order\OrderData;
use App\Domain\Order\OrderStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderDataTest extends TestCase
{
    public function testComputesAmountsAndReviewFlagWithoutFrameworkOrDatabase(): void
    {
        $data = $this->order([['sku' => 'SKU', 'name' => 'Товар', 'qty' => 2, 'unit_price_cents' => 180_000]], 300_000);

        self::assertSame(360_000, $data->itemsTotalCents);
        self::assertSame(70_000, $data->deliveryCostCents);
        self::assertSame(430_000, $data->grandTotalCents);
        self::assertTrue($data->needsReview);
    }

    #[DataProvider('overflowingItems')]
    public function testPreventsOverflowInLineItemsAndGrandTotal(array $items): void
    {
        try {
            $this->order($items, 0);
            self::fail('Overflowing amounts must be rejected.');
        } catch (InvalidOrderException $exception) {
            self::assertSame('amount_too_large', $exception->reasonCode);
        }
    }

    public static function overflowingItems(): iterable
    {
        $item = ['sku' => '', 'name' => '', 'qty' => 1, 'unit_price_cents' => PHP_INT_MAX];
        yield 'line multiplication' => [[array_replace($item, ['qty' => 2])]];
        yield 'sum of items' => [[$item, $item]];
        yield 'delivery added to items' => [[$item]];
    }

    private function order(array $items, int $marketplaceTotal): OrderData
    {
        return new OrderData(
            'MP-1009', OrderStatus::New, new \DateTimeImmutable('2026-10-09T16:00:00+03:00'),
            'Покупатель', '+79001234567', 'delivery', 'suburb', 'Адрес',
            new \DateTimeImmutable('2026-10-13T10:00:00+03:00'),
            new \DateTimeImmutable('2026-10-13T12:00:00+03:00'), $items, $marketplaceTotal,
        );
    }
}
