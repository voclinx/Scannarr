<?php

namespace App\Controller;

use App\Entity\TrackerRule;
use App\Repository\TrackerRuleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/tracker-rules')]
class TrackerRuleController extends AbstractController
{
    public function __construct(
        private readonly TrackerRuleRepository $trackerRuleRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', methods: ['GET'])]
    #[IsGranted('ROLE_ADVANCED_USER')]
    public function list(): JsonResponse
    {
        $rules = $this->trackerRuleRepository->findAllOrderedByDomain();

        return $this->json([
            'data' => array_map($this->serialize(...), $rules),
        ]);
    }

    #[Route('/{id}', methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function update(string $id, Request $request): JsonResponse
    {
        $rule = $this->trackerRuleRepository->find($id);

        if (!$rule instanceof TrackerRule) {
            return $this->json(
                ['error' => ['code' => 404, 'message' => 'Tracker rule not found']],
                Response::HTTP_NOT_FOUND,
            );
        }

        $payload = json_decode($request->getContent(), true);

        if (!$payload) {
            return $this->json(
                ['error' => ['code' => 400, 'message' => 'Invalid JSON']],
                Response::HTTP_BAD_REQUEST,
            );
        }

        if (isset($payload['min_seed_time_hours'])) {
            $rule->setMinSeedTimeHours((int)$payload['min_seed_time_hours']);
        }
        if (isset($payload['min_ratio'])) {
            $rule->setMinRatio((string)$payload['min_ratio']);
        }

        $this->em->flush();

        return $this->json(['data' => $this->serialize($rule)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(TrackerRule $rule): array
    {
        return [
            'id' => (string)$rule->getId(),
            'tracker_domain' => $rule->getTrackerDomain(),
            'min_seed_time_hours' => $rule->getMinSeedTimeHours(),
            'min_ratio' => $rule->getMinRatio(),
            'is_auto_detected' => $rule->isAutoDetected(),
            'created_at' => $rule->getCreatedAt()->format('c'),
            'updated_at' => $rule->getUpdatedAt()->format('c'),
        ];
    }
}
