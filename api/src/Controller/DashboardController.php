<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\DashboardService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1')]
class DashboardController extends AbstractController
{
    public function __construct(
        private readonly DashboardService $dashboardService,
    ) {
    }

    #[Route('/dashboard', methods: ['GET'])]
    #[IsGranted('ROLE_GUEST')]
    public function index(): JsonResponse
    {
        return $this->json(['data' => $this->dashboardService->getStats()]);
    }
}
