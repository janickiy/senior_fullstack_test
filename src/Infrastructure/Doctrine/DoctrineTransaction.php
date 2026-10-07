<?php

namespace App\Infrastructure\Doctrine;

use App\Application\Order\PersistenceException;
use App\Application\Order\Port\TransactionInterface;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(TransactionInterface::class)]
final class DoctrineTransaction implements TransactionInterface
{
    private const int MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function run(callable $operation): mixed
    {
        for ($attempt = 1; ; ++$attempt) {
            $manager = $this->registry->getManager();
            if (!$manager instanceof EntityManagerInterface) {
                throw new \LogicException('Doctrine ORM is required.');
            }
            if (!$manager->isOpen()) {
                $manager = $this->registry->resetManager();
            }

            try {
                return $manager->wrapInTransaction($operation);
            } catch (\Throwable $exception) {
                // MySQL может откатить всю транзакцию при deadlock, включая точки
                // сохранения ORM. Сбрасываем соединение и менеджер перед новым заказом.
                $manager->getConnection()->close();
                $this->registry->resetManager();

                if ($attempt < self::MAX_ATTEMPTS && $this->isRetryable($exception)) {
                    usleep(random_int(10_000, 50_000));
                    continue;
                }
                if ($exception instanceof DbalException || $exception instanceof ORMException) {
                    $this->logger->error('Failed to persist imported order.', ['exception' => $exception]);
                    throw new PersistenceException('Failed to persist order.', previous: $exception);
                }

                throw $exception;
            }
        }
    }

    private function isRetryable(\Throwable $exception): bool
    {
        do {
            if ($exception instanceof UniqueConstraintViolationException || $exception instanceof RetryableException) {
                return true;
            }
            // Ошибка отката savepoint может скрывать первоначальный deadlock.
            $exception = $exception->getPrevious();
        } while (null !== $exception);

        return false;
    }
}
