<?php

namespace App\Repository;

use App\Application\Order\OrderCriteria;
use App\Application\Order\Port\OrderRepositoryInterface;
use App\Application\Order\Result\OrderPage;
use App\Domain\Order\MarketplaceOrder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/** @extends ServiceEntityRepository<MarketplaceOrder> */
#[AsAlias(OrderRepositoryInterface::class)]
final class OrderRepository extends ServiceEntityRepository implements OrderRepositoryInterface
{
    /** Подключает репозиторий к менеджеру Doctrine, обслуживающему заказы. */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketplaceOrder::class);
    }

    /** Читает актуальные данные заказа и блокирует строку до завершения транзакции импорта. */
    public function findForImport(string $shopId, string $marketplaceId): ?MarketplaceOrder
    {
        return $this->createQueryBuilder('orders')
            ->where('orders.shopId = :shop')->setParameter('shop', $shopId)
            ->andWhere('orders.marketplaceId = :marketplace')->setParameter('marketplace', $marketplaceId)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }

    /** Регистрирует новый или изменённый заказ для сохранения при фиксации текущей транзакции. */
    public function save(MarketplaceOrder $order): void
    {
        $this->getEntityManager()->persist($order);
    }

    /** Возвращает страницу заказов магазина с фильтром по статусу и общим количеством. */
    public function findPageForShop(string $shopId, OrderCriteria $criteria): OrderPage
    {
        $builder = $this->createQueryBuilder('orders')
            ->where('orders.shopId = :shop')->setParameter('shop', $shopId);
        if (null !== $criteria->status) {
            $builder->andWhere('orders.status = :status')->setParameter('status', $criteria->status->value);
        }
        $total = (int) (clone $builder)->select('COUNT(orders.id)')->getQuery()->getSingleScalarResult();
        $orders = $builder->orderBy('orders.createdAt', 'DESC')->addOrderBy('orders.id', 'DESC')
            ->setFirstResult($criteria->offset())
            ->setMaxResults($criteria->limit)
            ->getQuery()->setHint(Query::HINT_REFRESH, true)->getResult();

        return new OrderPage($orders, $criteria->page, $criteria->limit, $total);
    }
}
