<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Attribute\Route;

final class StatusController extends AbstractController
{
    #[Route('/', name: 'api_status', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return $this->json([
            'status' => 'ok',
            'framework' => 'Symfony',
            'version' => Kernel::VERSION,
        ]);
    }
}
