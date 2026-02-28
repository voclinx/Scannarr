<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Security\Voter\FileVoter;
use App\Service\FileService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/files')]
class FileController extends AbstractController
{
    public function __construct(private readonly FileService $fileService)
    {
    }

    #[Route('', methods: ['GET'])]
    #[IsGranted('ROLE_GUEST')]
    public function index(Request $request): JsonResponse
    {
        $result = $this->fileService->list($request->query->all());

        return $this->json(['data' => $result['data'], 'meta' => $result['meta']]);
    }

    #[Route('/{id}', methods: ['GET'])]
    #[IsGranted('ROLE_GUEST')]
    public function show(string $id): JsonResponse
    {
        $file = $this->fileService->findById($id);
        if ($file === null) {
            return $this->json(['error' => ['code' => 404, 'message' => 'File not found']], 404);
        }

        return $this->json(['data' => $this->fileService->serializeFile($file)]);
    }

    #[Route('/{id}', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADVANCED_USER')]
    public function delete(string $id, Request $request): JsonResponse
    {
        $file = $this->fileService->findById($id);
        if ($file === null) {
            return $this->json(['error' => ['code' => 404, 'message' => 'File not found']], 404);
        }

        $this->denyAccessUnlessGranted(FileVoter::DELETE, $file);

        $data = json_decode($request->getContent(), true) ?? [];
        /** @var User $user */
        $user = $this->getUser();

        $result = $this->fileService->deleteFile(
            $file,
            (bool)($data['delete_physical'] ?? false),
            (bool)($data['delete_radarr_reference'] ?? false),
            (bool)($data['disable_radarr_auto_search'] ?? false),
            $user,
        );

        return $this->json(['data' => ['message' => 'Deletion initiated', 'deletion_id' => $result['deletion_id'], 'status' => $result['status']]], $result['http_code']);
    }

    #[Route('/{id}/siblings', methods: ['GET'])]
    #[IsGranted('ROLE_GUEST')]
    public function siblings(string $id): JsonResponse
    {
        $file = $this->fileService->findById($id);
        if ($file === null) {
            return $this->json(['error' => ['code' => 404, 'message' => 'File not found']], 404);
        }

        $result = $this->fileService->getSiblings($file);

        return $this->json(['data' => $result['data'], 'meta' => $result['meta']]);
    }

    #[Route('/{id}/global', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADVANCED_USER')]
    public function globalDelete(string $id, Request $request): JsonResponse
    {
        $sourceFile = $this->fileService->findById($id);
        if ($sourceFile === null) {
            return $this->json(['error' => ['code' => 404, 'message' => 'File not found']], 404);
        }

        $this->denyAccessUnlessGranted(FileVoter::DELETE, $sourceFile);

        $data = json_decode($request->getContent(), true) ?? [];
        /** @var User $user */
        $user = $this->getUser();

        $result = $this->fileService->globalDeleteFile(
            $sourceFile,
            (bool)($data['delete_physical'] ?? false),
            (bool)($data['delete_radarr_reference'] ?? false),
            (bool)($data['disable_radarr_auto_search'] ?? false),
            $user,
        );

        $httpCode = $result['http_code'];
        $responseData = ['message' => 'Global deletion initiated', 'deletion_id' => $result['deletion_id'], 'status' => $result['status'], 'files_count' => $result['files_count']];
        if (isset($result['warning'])) {
            $responseData['warning'] = $result['warning'];
        }

        return $this->json(['data' => $responseData], $httpCode);
    }
}
