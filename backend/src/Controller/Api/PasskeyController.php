<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Entity\WebauthnCredential;
use App\Repository\WebauthnCredentialRepository;
use App\Security\Webauthn\CredentialPayload;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/passkeys')]
final class PasskeyController extends AbstractController
{
    public function __construct(
        private readonly WebauthnCredentialRepository $credentials,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'api_passkeys_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        $items = $user->getWebauthnCredentials()
            ->map(static fn (WebauthnCredential $c): array => CredentialPayload::from($c))
            ->getValues();

        return new JsonResponse(['items' => $items]);
    }

    #[Route('/{id}', name: 'api_passkeys_rename', methods: ['PATCH'])]
    public function rename(string $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $credential = $this->findOwned($id, $user);

        if ($credential === null) {
            return $this->notFound();
        }

        $name = $request->toArray()['name'] ?? null;

        if (!\is_string($name) || trim($name) === '') {
            return new JsonResponse([
                'status' => 'failed',
                'errors' => ['name' => 'validation.required'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $credential->setName(mb_substr(trim($name), 0, 100));
        $this->entityManager->flush();

        return new JsonResponse(CredentialPayload::from($credential));
    }

    #[Route('/{id}', name: 'api_passkeys_delete', methods: ['DELETE'])]
    public function delete(string $id, #[CurrentUser] User $user): JsonResponse
    {
        $credential = $this->findOwned($id, $user);

        if ($credential === null) {
            return $this->notFound();
        }

        // Removing the last passkey is fine only while another way in
        // remains; otherwise the account would lock itself out. Counting in
        // the database avoids relying on a lazily loaded collection.
        if ($user->getPassword() === null && $this->credentials->countForUser($user) <= 1) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => 'passkey.lastOneWithoutPassword',
            ], Response::HTTP_CONFLICT);
        }

        $this->entityManager->remove($credential);
        $this->entityManager->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    private function findOwned(string $id, User $user): ?WebauthnCredential
    {
        $credential = $this->credentials->find($id);

        // Comparing owners keeps one account from touching another's keys.
        return $credential?->getUser()->getId()->equals($user->getId()) === true ? $credential : null;
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(['status' => 'failed', 'error' => 'errors.notFound'], Response::HTTP_NOT_FOUND);
    }
}
