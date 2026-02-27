<?php

namespace App\Controller;

use App\Entity\DeletionPreset;
use App\Entity\User;
use App\Repository\DeletionPresetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/deletion-presets')]
class DeletionPresetController extends AbstractController
{
    public function __construct(
        private readonly DeletionPresetRepository $presetRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function list(): JsonResponse
    {
        $presets = $this->presetRepository->findAllOrderedByName();

        return $this->json([
            'data' => array_map($this->serialize(...), $presets),
        ]);
    }

    #[Route('', methods: ['POST'])]
    #[IsGranted('ROLE_ADVANCED_USER')]
    public function create(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);

        if (!$payload) {
            return $this->json(
                ['error' => ['code' => 400, 'message' => 'Invalid JSON']],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $name = $payload['name'] ?? null;
        $criteria = $payload['criteria'] ?? null;

        if (!$name || trim((string)$name) === '') {
            return $this->json(
                ['error' => ['code' => 422, 'message' => 'Validation failed', 'details' => ['name' => 'Name is required']]],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if ($criteria === null) {
            return $this->json(
                ['error' => ['code' => 422, 'message' => 'Validation failed', 'details' => ['criteria' => 'Criteria is required']]],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        /** @var User $user */
        $user = $this->getUser();

        $preset = new DeletionPreset();
        $preset->setName(trim((string)$name));
        $preset->setCriteria($criteria);
        $preset->setFilters($payload['filters'] ?? []);
        $preset->setIsSystem(false);
        $preset->setCreatedBy($user);

        $this->em->persist($preset);
        $this->em->flush();

        return $this->json(['data' => $this->serialize($preset)], Response::HTTP_CREATED);
    }

    #[Route('/{id}', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function detail(string $id): JsonResponse
    {
        $preset = $this->presetRepository->find($id);

        if (!$preset instanceof DeletionPreset) {
            return $this->json(
                ['error' => ['code' => 404, 'message' => 'Preset not found']],
                Response::HTTP_NOT_FOUND,
            );
        }

        return $this->json(['data' => $this->serialize($preset)]);
    }

    #[Route('/{id}', methods: ['PUT'])]
    #[IsGranted('ROLE_ADVANCED_USER')]
    public function update(string $id, Request $request): JsonResponse
    {
        $preset = $this->presetRepository->find($id);

        if (!$preset instanceof DeletionPreset) {
            return $this->json(
                ['error' => ['code' => 404, 'message' => 'Preset not found']],
                Response::HTTP_NOT_FOUND,
            );
        }

        if ($preset->isSystem()) {
            return $this->json(
                ['error' => ['code' => 403, 'message' => 'Cannot modify a system preset']],
                Response::HTTP_FORBIDDEN,
            );
        }

        $payload = json_decode($request->getContent(), true);

        if (!$payload) {
            return $this->json(
                ['error' => ['code' => 400, 'message' => 'Invalid JSON']],
                Response::HTTP_BAD_REQUEST,
            );
        }

        if (isset($payload['name'])) {
            $preset->setName(trim((string)$payload['name']));
        }
        if (array_key_exists('criteria', $payload)) {
            $preset->setCriteria($payload['criteria']);
        }
        if (array_key_exists('filters', $payload)) {
            $preset->setFilters($payload['filters']);
        }

        if (isset($payload['is_default']) && $payload['is_default'] === true) {
            $currentDefault = $this->presetRepository->findDefault();
            if ($currentDefault instanceof DeletionPreset && $currentDefault->getId() !== $preset->getId()) {
                $currentDefault->setIsDefault(false);
            }
            $preset->setIsDefault(true);
        } elseif (isset($payload['is_default']) && $payload['is_default'] === false) {
            $preset->setIsDefault(false);
        }

        $this->em->flush();

        return $this->json(['data' => $this->serialize($preset)]);
    }

    #[Route('/{id}', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADVANCED_USER')]
    public function delete(string $id): JsonResponse
    {
        $preset = $this->presetRepository->find($id);

        if (!$preset instanceof DeletionPreset) {
            return $this->json(
                ['error' => ['code' => 404, 'message' => 'Preset not found']],
                Response::HTTP_NOT_FOUND,
            );
        }

        if ($preset->isSystem()) {
            return $this->json(
                ['error' => ['code' => 403, 'message' => 'Cannot delete a system preset']],
                Response::HTTP_FORBIDDEN,
            );
        }

        $this->em->remove($preset);
        $this->em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(DeletionPreset $preset): array
    {
        $createdBy = $preset->getCreatedBy();

        return [
            'id' => (string)$preset->getId(),
            'name' => $preset->getName(),
            'is_system' => $preset->isSystem(),
            'is_default' => $preset->isDefault(),
            'criteria' => $preset->getCriteria(),
            'filters' => $preset->getFilters(),
            'created_by' => $createdBy instanceof User ? [
                'id' => (string)$createdBy->getId(),
                'username' => $createdBy->getUsername(),
            ] : null,
            'created_at' => $preset->getCreatedAt()->format('c'),
            'updated_at' => $preset->getUpdatedAt()->format('c'),
        ];
    }
}
