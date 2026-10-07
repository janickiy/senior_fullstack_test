<?php

namespace App\Controller\Api;

use App\Application\Order\ImportOrders;
use App\Application\Order\ListOrders;
use App\Application\Order\OrderCriteria;
use App\Controller\Api\Request\ImportOrdersRequestDto;
use App\Controller\Api\Request\OrderListQueryDto;
use App\Domain\Order\OrderStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/shops/{shopId}/orders', requirements: ['shopId' => '[A-Za-z0-9_-]{1,64}'], format: 'json')]
final class OrderController extends AbstractController
{
    public function __construct(private readonly OrderResponse $response)
    {
    }

    #[Route('/import', name: 'api_orders_import', methods: ['POST'])]
    public function import(
        string $shopId,
        #[MapRequestPayload(acceptFormat: 'json')]
        ImportOrdersRequestDto $request,
        ImportOrders $importer,
    ): JsonResponse {
        return $this->json($this->response->batch($importer->import($shopId, $request->orders)));
    }

    #[Route('', name: 'api_orders_list', methods: ['GET'])]
    public function list(
        string $shopId,
        #[MapQueryString(validationFailedStatusCode: 422)]
        OrderListQueryDto $query,
        ListOrders $orders,
    ): JsonResponse {
        $criteria = new OrderCriteria(null === $query->status ? null : OrderStatus::from($query->status), $query->page, $query->limit);

        return $this->json($this->response->page($orders->list($shopId, $criteria)));
    }
}
