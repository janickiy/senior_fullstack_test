<?php

namespace App\Application\Order\Port;

interface TransactionInterface
{
    /**
     * Выполняет операцию атомарно и возвращает результат после сохранения изменений.
     * При временном конфликте может повторить операцию; callback не должен иметь внешних побочных эффектов.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function run(callable $operation): mixed;
}
