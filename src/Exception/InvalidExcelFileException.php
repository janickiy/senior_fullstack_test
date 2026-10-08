<?php

namespace App\Exception;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Сообщает об ошибке формата или структуры Excel-файла до начала импорта заказов. */
#[Exclude]
final class InvalidExcelFileException extends \RuntimeException
{
}
