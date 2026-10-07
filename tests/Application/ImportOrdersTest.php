<?php

namespace App\Tests\Application;

use App\Application\Order\ImportOrder;
use App\Application\Order\ImportOrders;
use App\Application\Order\PersistenceException;
use App\Application\Order\Port\OrderNormalizerInterface;
use App\Application\Order\Port\OrderRepositoryInterface;
use App\Application\Order\Port\TransactionInterface;
use App\Domain\Order\InvalidOrderException;
use App\Domain\Order\MarketplaceOrder;
use App\Domain\Order\OrderData;
use App\Domain\Order\OrderStatus;
use PHPUnit\Framework\TestCase;

final class ImportOrdersTest extends TestCase
{
    public function testInvalidFirstOccurrenceStillMakesNextOccurrenceADuplicate(): void
    {
        $normalizer = $this->createMock(OrderNormalizerInterface::class);
        $normalizer->method('extractId')->willReturnCallback(static fn (array $raw): string => $raw['id']);
        $normalizer->expects(self::exactly(2))->method('normalize')->willReturnCallback(function (array $raw): OrderData {
            if ('bad' === $raw['id']) {
                throw new InvalidOrderException('invalid_phone', 'Некорректный телефон.');
            }

            return $this->data($raw['id']);
        });
        $repository = $this->createMock(OrderRepositoryInterface::class);
        $repository->expects(self::once())->method('findForImport')->with('shop', 'good')->willReturn(null);
        $repository->expects(self::once())->method('save')->with(self::isInstanceOf(MarketplaceOrder::class));
        $importer = new ImportOrders($normalizer, new ImportOrder($repository, $this->transaction()));

        $results = $importer->import('shop', [['id' => 'bad'], ['id' => 'bad'], ['id' => 'good']]);

        self::assertSame(['rejected', 'duplicate', 'created'], array_column($results, 'outcome'));
        self::assertSame('invalid_phone', $results[0]->code);
        self::assertSame('duplicate_in_batch', $results[1]->code);
        self::assertSame('good', $results[2]->saved->order->getMarketplaceId());
    }

    public function testPersistenceFailureDoesNotPreventImportingTheNextOrder(): void
    {
        $normalizer = $this->createStub(OrderNormalizerInterface::class);
        $normalizer->method('extractId')->willReturnCallback(static fn (array $raw): string => $raw['id']);
        $normalizer->method('normalize')->willReturnCallback(fn (array $raw): OrderData => $this->data($raw['id']));
        $repository = $this->createMock(OrderRepositoryInterface::class);
        $repository->expects(self::once())->method('findForImport')->with('shop', 'second')->willReturn(null);
        $repository->expects(self::once())->method('save');
        $transaction = new class implements TransactionInterface {
            private int $calls = 0;

            public function run(callable $operation): mixed
            {
                if (0 === $this->calls++) {
                    throw new PersistenceException('Storage unavailable.');
                }

                return $operation();
            }
        };
        $importer = new ImportOrders($normalizer, new ImportOrder($repository, $transaction));

        $results = $importer->import('shop', [['id' => 'first'], ['id' => 'second']]);

        self::assertSame(['rejected', 'created'], array_column($results, 'outcome'));
        self::assertSame('persistence_error', $results[0]->code);
        self::assertSame('second', $results[1]->saved->order->getMarketplaceId());
    }

    public function testExistingOrderChangesOnlyStatusAndKeepsFinalStatus(): void
    {
        $existing = new MarketplaceOrder('shop', $this->data('MP-1'));
        $repository = $this->createMock(OrderRepositoryInterface::class);
        $repository->expects(self::exactly(3))->method('findForImport')->with('shop', 'MP-1')->willReturn($existing);
        $repository->expects(self::once())->method('save')->with($existing);
        $importer = new ImportOrder($repository, $this->transaction());

        $updated = $importer->import('shop', $this->data('MP-1', OrderStatus::Delivered, 900));
        self::assertSame('updated', $updated->outcome);
        self::assertSame(OrderStatus::Delivered, $existing->getStatus());
        self::assertSame(100, $existing->getItemsTotalCents());

        $locked = $importer->import('shop', $this->data('MP-1', OrderStatus::Accepted));
        self::assertSame('final_status_locked', $locked->code);
        self::assertSame(OrderStatus::Delivered, $locked->order->getStatus());

        $unchanged = $importer->import('shop', $this->data('MP-1', OrderStatus::Delivered));
        self::assertSame('no_changes', $unchanged->code);
    }

    private function transaction(): TransactionInterface
    {
        return new class implements TransactionInterface {
            public function run(callable $operation): mixed
            {
                return $operation();
            }
        };
    }

    private function data(string $id, OrderStatus $status = OrderStatus::New, int $price = 100): OrderData
    {
        return new OrderData(
            $id, $status, new \DateTimeImmutable('2026-10-10T10:00:00+03:00'),
            'Покупатель', '+79001234567', 'pickup', null, null, null, null,
            [['sku' => 'SKU', 'name' => 'Товар', 'qty' => 1, 'unit_price_cents' => $price]], $price,
        );
    }
}
