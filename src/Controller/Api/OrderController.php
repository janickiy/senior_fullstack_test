<?php

namespace App\Controller\Api;

use App\DTO\Order\ImportOrdersRequestDto;
use App\DTO\Order\OrderListQueryDto;
use App\Repository\OrderRepositoryInterface;
use App\Service\OrderImportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/shops/{shopId}/orders', requirements: ['shopId' => '[A-Za-z0-9_-]{1,64}'], format: 'json')]
final class OrderController extends AbstractController
{
    #[Route('/import', name: 'api_orders_import', methods: ['POST'])]
    public function import(
        string $shopId,
        #[MapRequestPayload(acceptFormat: 'json')]
        ImportOrdersRequestDto $request,
        OrderImportService $importer,
    ): JsonResponse {
        return $this->json($importer->import($shopId, $request)->toArray());
    }

    #[Route('', name: 'api_orders_list', methods: ['GET'])]
    public function list(
        string $shopId,
        #[MapQueryString(validationFailedStatusCode: 422)]
        OrderListQueryDto $query,
        OrderRepositoryInterface $orders,
    ): JsonResponse {
        return $this->json($orders->findPageForShop($shopId, $query)->toArray());
    }
}
