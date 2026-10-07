<?php

namespace App\Tests\Integration;

use App\DTO\Order\OrderListQueryDto;
use App\Entity\MarketplaceOrder;
use App\Enum\OrderStatus;
use App\Repository\OrderRepositoryInterface;
use App\Service\Import\OrderNormalizer;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OrderRepositoryTest extends KernelTestCase
{
    private OrderRepositoryInterface $orders;
    private OrderNormalizer $normalizer;
    private ManagerRegistry $registry;
    private string $shopId;

    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
        $this->orders = self::getContainer()->get(OrderRepositoryInterface::class);
        $this->normalizer = self::getContainer()->get(OrderNormalizer::class);
        $this->registry = self::getContainer()->get(ManagerRegistry::class);
        $this->shopId = 'test-orm-'.bin2hex(random_bytes(8));
    }

    public function testImportReturnsSavedValuesAndAnUpdatePreservesOrderData(): void
    {
        $raw = $this->fixture()['orders'][8];
        $order = $this->normalizer->normalize($raw);

        $created = $this->orders->import($this->shopId, $order);

        self::assertSame('created', $created->outcome);
        self::assertGreaterThan(0, $created->orderId);
        self::assertSame($order->status, $created->status);
        self::assertSame($order->itemsTotalCents, $created->itemsTotalCents);
        self::assertSame($order->deliveryCostCents, $created->deliveryCostCents);
        self::assertSame($order->grandTotalCents, $created->grandTotalCents);
        self::assertTrue($created->needsReview);
        $before = $this->orders->findPageForShop($this->shopId, new OrderListQueryDto())->items[0]->toArray();

        $raw['status'] = 'ACCEPTED';
        $raw['created_at'] = '2026-10-11T09:00:00+03:00';
        $raw['customer'] = ['name' => 'Другой покупатель', 'phone' => '+79001112233'];
        $raw['delivery'] = ['type' => 'pickup'];
        $raw['items'] = [['sku' => 'OTHER', 'name' => 'Другой товар', 'qty' => 1, 'price' => 100]];
        $raw['total'] = 100;

        $updated = $this->orders->import($this->shopId, $this->normalizer->normalize($raw));

        self::assertSame('updated', $updated->outcome);
        self::assertSame('status_updated', $updated->code);
        self::assertSame($created->orderId, $updated->orderId);
        self::assertSame('accepted', $updated->status);
        self::assertSame($created->itemsTotalCents, $updated->itemsTotalCents);
        self::assertSame($created->deliveryCostCents, $updated->deliveryCostCents);
        self::assertSame($created->grandTotalCents, $updated->grandTotalCents);
        self::assertSame($created->needsReview, $updated->needsReview);
        $before['status'] = 'accepted';
        self::assertSame($before, $this->orders->findPageForShop($this->shopId, new OrderListQueryDto())->items[0]->toArray());
    }

    public function testImportRefreshesAFinalStatusChangedByAnotherEntityManager(): void
    {
        $raw = $this->fixture()['orders'][0];
        $created = $this->orders->import($this->shopId, $this->normalizer->normalize($raw));
        $manager = $this->manager();
        $loaded = $manager->find(MarketplaceOrder::class, $created->orderId);
        self::assertInstanceOf(MarketplaceOrder::class, $loaded);
        self::assertSame(OrderStatus::New, $loaded->getStatus());

        $otherManager = new EntityManager($manager->getConnection(), $manager->getConfiguration());
        try {
            $delivered = $otherManager->find(MarketplaceOrder::class, $created->orderId);
            self::assertInstanceOf(MarketplaceOrder::class, $delivered);
            $raw['status'] = 'DONE';
            $delivered->applyImportedStatus($this->normalizer->normalize($raw));
            $otherManager->flush();
        } finally {
            $otherManager->close();
        }

        self::assertSame(OrderStatus::New, $loaded->getStatus());
        $raw['status'] = 'ACCEPTED';

        $result = $this->orders->import($this->shopId, $this->normalizer->normalize($raw));

        self::assertSame('unchanged', $result->outcome);
        self::assertSame('final_status_locked', $result->code);
        self::assertSame('delivered', $result->status);
        $page = $this->orders->findPageForShop($this->shopId, new OrderListQueryDto());
        self::assertSame(1, $page->total);
        self::assertSame('delivered', $page->items[0]->status);
    }

    public function testRepositoryCanPersistTheNextOrderAfterAFlushFailure(): void
    {
        $order = $this->normalizer->normalize($this->fixture()['orders'][0]);
        $invalidShopId = $this->shopId.str_repeat('x', 65);

        try {
            $this->orders->import($invalidShopId, $order);
            self::fail('MySQL must reject a shop identifier exceeding the mapped column length.');
        } catch (DriverException $exception) {
            self::assertSame('22001', $exception->getSQLState());
        }

        $created = $this->orders->import($this->shopId, $order);
        $page = $this->orders->findPageForShop($this->shopId, new OrderListQueryDto());

        self::assertSame('created', $created->outcome);
        self::assertTrue($this->manager()->isOpen());
        self::assertSame(1, $page->total);
        self::assertSame($created->orderId, $page->items[0]->id);
        self::assertSame($order->marketplaceId, $page->items[0]->marketplaceId);
        self::assertSame(0, $this->orders->findPageForShop($invalidShopId, new OrderListQueryDto())->total);
    }

    private function manager(): EntityManagerInterface
    {
        $manager = $this->registry->getManagerForClass(MarketplaceOrder::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }

    private function fixture(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__, 2).'/public/assets/marketplace-orders.json'), true, flags: JSON_THROW_ON_ERROR);
    }
}
