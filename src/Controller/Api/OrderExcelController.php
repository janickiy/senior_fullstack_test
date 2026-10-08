<?php

namespace App\Controller\Api;

use App\DTO\Order\ImportOrdersRequestDto;
use App\Exception\InvalidExcelFileException;
use App\Repository\OrderRepositoryInterface;
use App\Service\Excel\OrderExcelService;
use App\Service\OrderImportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Attribute\MapUploadedFile;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;

/** Принимает Excel-файлы с заказами и отдаёт выгрузку или шаблон для заполнения. */
#[Route('/api/shops/{shopId}/orders', requirements: ['shopId' => '[A-Za-z0-9_-]{1,64}'], format: 'json')]
final class OrderExcelController extends AbstractController
{
    #[Route('/import/excel', name: 'api_orders_import_excel', methods: ['POST'])]
    public function import(
        string $shopId,
        OrderExcelService $excel,
        OrderImportService $importer,
        #[MapUploadedFile(constraints: new Assert\File(
            maxSize: 10 * 1024 * 1024,
            extensions: ['xlsx'],
            maxSizeMessage: 'Размер Excel-файла не должен превышать 10 МБ.',
            extensionsMessage: 'Выберите файл Excel в формате .xlsx.',
            mimeTypesMessage: 'Файл должен быть корректной книгой Excel в формате .xlsx.',
            disallowEmptyMessage: 'Excel-файл пуст.',
            uploadPartialErrorMessage: 'Файл загружен не полностью. Повторите загрузку.',
        ))]
        ?UploadedFile $file = null,
    ): JsonResponse {
        if (null === $file) {
            throw new UnprocessableEntityHttpException('Выберите файл Excel в формате .xlsx.');
        }
        try {
            $orders = $excel->read($file->getPathname());
        } catch (InvalidExcelFileException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        }
        if ([] === $orders) {
            throw new UnprocessableEntityHttpException('Excel-файл не содержит заказов.');
        }

        $result = $importer->import($shopId, new ImportOrdersRequestDto($orders))->toArray();
        foreach ($result['results'] as &$row) {
            $row['source_row'] = $orders[$row['index']]['_source_row'] ?? $row['index'] + 2;
        }
        unset($row);

        return $this->json($result);
    }

    #[Route('/export/excel', name: 'api_orders_export_excel', methods: ['GET'])]
    public function export(string $shopId, OrderExcelService $excel, OrderRepositoryInterface $orders): BinaryFileResponse
    {
        return $this->download(
            static fn (string $path) => $excel->write($orders->iterateForShop($shopId), $path),
            'orders-shop-'.$shopId.'.xlsx',
        );
    }

    #[Route('/template/excel', name: 'api_orders_template_excel', methods: ['GET'])]
    public function template(OrderExcelService $excel): BinaryFileResponse
    {
        return $this->download($excel->writeTemplate(...), 'orders-template.xlsx');
    }

    /** Создаёт временный файл и удаляет его после отправки или ошибки формирования. */
    private function download(callable $write, string $filename): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'orders-excel-');
        if (false === $path) {
            throw new \RuntimeException('Unable to create Excel download.');
        }
        try {
            $write($path);
            clearstatcache(true, $path);

            $response = new BinaryFileResponse($path, headers: [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'private, no-store',
            ], public: false);
            $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);

            return $response->deleteFileAfterSend(true);
        } catch (\Throwable $exception) {
            if (is_file($path)) {
                unlink($path);
            }

            throw $exception;
        }
    }
}
