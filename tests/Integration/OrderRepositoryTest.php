<?php

namespace App\Tests\Integration;

use App\Application\Order\ImportOrder;
use App\Application\Order\OrderCriteria;
use App\Application\Order\PersistenceException;
use App\Application\Order\Port\OrderRepositoryInterface;
use App\Controller\Api\OrderResponse;
use App\Domain\Order\MarketplaceOrder;
use App\Domain\Order\OrderStatus;
use App\Infrastructure\Marketplace\OrderNormalizer;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OrderRepositoryTest extends KernelTestCase
{
    private OrderRepositoryInterface $orders;
    private ImportOrder $importer;
    private OrderNormalizer $normalizer;
    private ManagerRegistry $registry;
    private string $shopId;

    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
        $this->orders = self::getContainer()->get(OrderRepositoryInterface::class);
        $this->importer = self::getContainer()->get(ImportOrder::class);
        $this->normalizer = self::getContainer()->get(OrderNormalizer::class);
        $this->registry = self::getContainer()->get(ManagerRegistry::class);
        $this->shopId = 'test-orm-'.bin2hex(random_bytes(8));
    }

    public function testImportReturnsSavedValuesAndAnUpdatePreservesOrderData(): void
    {
        $raw = $this->fixture()['orders'][8];
        $order = $this->normalizer->normalize($raw);

        $created = $this->importer->import($this->shopId, $order);

        self::assertSame('created', $created->outcome);
        self::assertGreaterThan(0, $created->order->getId());
        self::assertSame($order->status->value, $created->order->getStatus()->value);
        self::assertSame($order->itemsTotalCents, $created->order->getItemsTotalCents());
        self::assertSame($order->deliveryCostCents, $created->order->getDeliveryCostCents());
        self::assertSame($order->grandTotalCents, $created->order->getGrandTotalCents());
        self::assertTrue($created->order->needsReview());
        $before = (new OrderResponse())->order($this->orders->findPageForShop($this->shopId, new OrderCriteria())->items[0]);

        $raw['status'] = 'ACCEPTED';
        $raw['created_at'] = '2026-10-11T09:00:00+03:00';
        $raw['customer'] = ['name' => 'Другой покупатель', 'phone' => '+79001112233'];
        $raw['delivery'] = ['type' => 'pickup'];
        $raw['items'] = [['sku' => 'OTHER', 'name' => 'Другой товар', 'qty' => 1, 'price' => 100]];
        $raw['total'] = 100;

        $updated = $this->importer->import($this->shopId, $this->normalizer->normalize($raw));

        self::assertSame('updated', $updated->outcome);
        self::assertSame('status_updated', $updated->code);
        self::assertSame($created->order->getId(), $updated->order->getId());
        self::assertSame('accepted', $updated->order->getStatus()->value);
        self::assertSame($created->order->getItemsTotalCents(), $updated->order->getItemsTotalCents());
        self::assertSame($created->order->getDeliveryCostCents(), $updated->order->getDeliveryCostCents());
        self::assertSame($created->order->getGrandTotalCents(), $updated->order->getGrandTotalCents());
        self::assertSame($created->order->needsReview(), $updated->order->needsReview());
        $before['status'] = 'accepted';
        self::assertSame($before, (new OrderResponse())->order($this->orders->findPageForShop($this->shopId, new OrderCriteria())->items[0]));
    }

    public function testImportRefreshesAFinalStatusChangedByAnotherEntityManager(): void
    {
        $raw = $this->fixture()['orders'][0];
        $created = $this->importer->import($this->shopId, $this->normalizer->normalize($raw));
        $manager = $this->manager();
        $loaded = $manager->find(MarketplaceOrder::class, $created->order->getId());
        self::assertInstanceOf(MarketplaceOrder::class, $loaded);
        self::assertSame(OrderStatus::New, $loaded->getStatus());

        $otherManager = new EntityManager($manager->getConnection(), $manager->getConfiguration());
        try {
            $delivered = $otherManager->find(MarketplaceOrder::class, $created->order->getId());
            self::assertInstanceOf(MarketplaceOrder::class, $delivered);
            $raw['status'] = 'DONE';
            $delivered->applyImportedStatus(OrderStatus::Delivered);
            $otherManager->flush();
        } finally {
            $otherManager->close();
        }

        self::assertSame(OrderStatus::New, $loaded->getStatus());
        $raw['status'] = 'ACCEPTED';

        $result = $this->importer->import($this->shopId, $this->normalizer->normalize($raw));

        self::assertSame('unchanged', $result->outcome);
        self::assertSame('final_status_locked', $result->code);
        self::assertSame('delivered', $result->order->getStatus()->value);
        $page = $this->orders->findPageForShop($this->shopId, new OrderCriteria());
        self::assertSame(1, $page->total);
        self::assertSame('delivered', $page->items[0]->getStatus()->value);
    }

    public function testRepositoryCanPersistTheNextOrderAfterAFlushFailure(): void
    {
        $order = $this->normalizer->normalize($this->fixture()['orders'][0]);
        $invalidShopId = $this->shopId.str_repeat('x', 65);

        try {
            $this->importer->import($invalidShopId, $order);
            self::fail('MySQL must reject a shop identifier exceeding the mapped column length.');
        } catch (PersistenceException $exception) {
            self::assertInstanceOf(DriverException::class, $exception->getPrevious());
            self::assertSame('22001', $exception->getPrevious()->getSQLState());
        }

        $created = $this->importer->import($this->shopId, $order);
        $page = $this->orders->findPageForShop($this->shopId, new OrderCriteria());

        self::assertSame('created', $created->outcome);
        self::assertTrue($this->manager()->isOpen());
        self::assertSame(1, $page->total);
        self::assertSame($created->order->getId(), $page->items[0]->getId());
        self::assertSame($order->marketplaceId, $page->items[0]->getMarketplaceId());
        self::assertSame(0, $this->orders->findPageForShop($invalidShopId, new OrderCriteria())->total);
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
