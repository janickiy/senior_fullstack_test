<?php

namespace App\Repository;

use App\DTO\Import\NormalizedOrderDto;
use App\DTO\Order\OrderListQueryDto;
use App\DTO\Order\OrderPageDto;
use App\DTO\Order\OrderViewDto;
use App\DTO\Order\PersistedOrderResultDto;
use App\Entity\MarketplaceOrder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/** @extends ServiceEntityRepository<MarketplaceOrder> */
#[AsAlias(OrderRepositoryInterface::class)]
final class OrderRepository extends ServiceEntityRepository implements OrderRepositoryInterface
{

    public function __construct(private readonly ManagerRegistry $managerRegistry)
    {
        parent::__construct($managerRegistry, MarketplaceOrder::class);
    }


    /**
     * Импортирует один подготовленный заказ магазина и возвращает результат в DTO.
     *
     * @param string $shopId
     * @param NormalizedOrderDto $order
     * @return PersistedOrderResultDto
     * @throws \Random\RandomException
     * @throws \Throwable
     */
    public function import(string $shopId, NormalizedOrderDto $order): PersistedOrderResultDto
    {
        for ($attempt = 0; ; ++$attempt) {
            try {
                $manager = $this->getEntityManager();
                if (!$manager->isOpen()) {
                    $this->managerRegistry->resetManager();
                    $manager = $this->getEntityManager();
                }

                return $this->importInTransaction($manager, $shopId, $order);
            } catch (\Throwable $exception) {
                // При взаимной блокировке MySQL может откатить всю транзакцию,
                // нарушив состояние вложенных точек сохранения ORM. Восстанавливаем
                // соединение и менеджер перед следующей попыткой или заказом.
                if (isset($manager)) {
                    $manager->getConnection()->close();
                }
                $this->managerRegistry->resetManager();
                if (!$this->isRetryable($exception) || $attempt >= 4) {
                    throw $exception;
                }
                usleep(random_int(10000, 50000));
            }
        }
    }

    /**
     * Проверяет, допускает ли ошибка повтор транзакции: ищет конфликт уникальности
     * или временную ошибку Doctrine во всей цепочке причин исключения.
     *
     * @param \Throwable $exception
     * @return bool
     */
    private function isRetryable(\Throwable $exception): bool
    {
        do {
            if ($exception instanceof UniqueConstraintViolationException || $exception instanceof RetryableException) {
                return true;
            }
            // Ошибка отката точки сохранения может скрыть исходный временный сбой
            // базы в цепочке предыдущих исключений.
            $exception = $exception->getPrevious();
        } while (null !== $exception);

        return false;
    }

    /**
     * В одной транзакции читает актуальный заказ с блокировкой строки, создаёт
     * новый заказ либо меняет только допустимый статус существующего.
     *
     * @param EntityManagerInterface $manager
     * @param string $shopId
     * @param NormalizedOrderDto $order
     * @return PersistedOrderResultDto
     */
    private function importInTransaction(EntityManagerInterface $manager, string $shopId, NormalizedOrderDto $order): PersistedOrderResultDto
    {
        [$saved, $outcome, $code, $message] = $manager->wrapInTransaction(
            function (EntityManagerInterface $manager) use ($shopId, $order): array {
                /** @var MarketplaceOrder|null $existing */
                $existing = $this->createQueryBuilder('orders')
                    ->where('orders.shopId = :shop')->setParameter('shop', $shopId)
                    ->andWhere('orders.marketplaceId = :marketplace')->setParameter('marketplace', $order->marketplaceId)
                    ->getQuery()
                    ->setHint(Query::HINT_REFRESH, true)
                    ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                    ->getOneOrNullResult();

                if (null === $existing) {
                    $created = new MarketplaceOrder($shopId, $order);
                    $manager->persist($created);

                    return [$created, 'created', 'created', 'Заказ создан.'];
                }

                if ($existing->applyImportedStatus($order)) {
                    return [$existing, 'updated', 'status_updated', 'Обновлён только статус заказа.'];
                }

                if ($existing->getStatus()->value !== $order->status) {
                    return [$existing, 'unchanged', 'final_status_locked', 'Финальный статус сохранён; изменение отклонено.'];
                }

                return [$existing, 'unchanged', 'no_changes', 'Статус не изменился; остальные данные сохранены без изменений.'];
            },
        );

        // wrapInTransaction выполняет flush и фиксирует транзакцию до возврата,
        // поэтому идентификатор и значения DTO берём из сохранённой ORM-сущности.
        return new PersistedOrderResultDto(
            $outcome, $code, $message, $saved->getId(), $saved->getStatus()->value,
            $saved->getItemsTotalCents(), $saved->getDeliveryCostCents(), $saved->getGrandTotalCents(),
            $saved->needsReview(),
        );
    }


    /**
     * Возвращает страницу заказов указанного магазина с фильтром по статусу.
     *
     * @param string $shopId
     * @param OrderListQueryDto $query
     * @return OrderPageDto
     */
    public function findPageForShop(string $shopId, OrderListQueryDto $query): OrderPageDto
    {
        $builder = $this->createQueryBuilder('orders')->where('orders.shopId = :shop')->setParameter('shop', $shopId);
        if (null !== $query->status) {
            $builder->andWhere('orders.status = :status')->setParameter('status', $query->status);
        }
        $total = (int) (clone $builder)->select('COUNT(orders.id)')->getQuery()->getSingleScalarResult();
        $orders = $builder->orderBy('orders.createdAt', 'DESC')->addOrderBy('orders.id', 'DESC')
            ->setFirstResult(($query->page - 1) * $query->limit)->setMaxResults($query->limit)->getQuery()->setHint(Query::HINT_REFRESH, true)->getResult();

        return new OrderPageDto(array_map(OrderViewDto::fromEntity(...), $orders), $query->page, $query->limit, $total);
    }
}
