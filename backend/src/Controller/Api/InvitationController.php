<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Invitation\AccountAlreadyExists;
use App\Invitation\InvitationService;
use App\Repository\InvitationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/invitations')]
final class InvitationController extends AbstractController
{
    private const ASSIGNABLE_ROLES = [User::ROLE_USER, User::ROLE_SERVER_ADMIN, User::ROLE_ADMIN];

    public function __construct(
        private readonly InvitationService $invitations,
        private readonly InvitationRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('', name: 'api_invitations_list', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function list(): JsonResponse
    {
        $items = array_map(
            static fn ($invitation): array => [
                'id' => $invitation->getId()->toRfc4122(),
                'email' => $invitation->getEmail(),
                'roles' => $invitation->getRoles(),
                'invitedBy' => $invitation->getInvitedBy()?->getDisplayName(),
                'createdAt' => $invitation->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'expiresAt' => $invitation->getExpiresAt()->format(\DateTimeInterface::ATOM),
                'acceptedAt' => $invitation->getAcceptedAt()?->format(\DateTimeInterface::ATOM),
                'pending' => $invitation->isPending(),
            ],
            $this->repository->findBy([], ['createdAt' => 'DESC'], 100),
        );

        return new JsonResponse(['items' => $items]);
    }

    #[Route('', name: 'api_invitations_create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function create(Request $request, #[CurrentUser] User $invitedBy): JsonResponse
    {
        $payload = $request->toArray();
        $email = $payload['email'] ?? null;
        $roles = $payload['roles'] ?? [User::ROLE_USER];

        $violations = $this->validator->validate($email, [new Assert\NotBlank(), new Assert\Email()]);

        if ($violations->count() > 0) {
            return new JsonResponse([
                'status' => 'failed',
                'errors' => ['email' => 'validation.emailInvalid'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!\is_array($roles) || array_diff($roles, self::ASSIGNABLE_ROLES) !== []) {
            return new JsonResponse([
                'status' => 'failed',
                'errors' => ['roles' => 'validation.invalid'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $invitation = $this->invitations->invite((string) $email, array_values($roles), $invitedBy);
        } catch (AccountAlreadyExists) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => 'invitations.accountExists',
            ], Response::HTTP_CONFLICT);
        }

        return new JsonResponse([
            'status' => 'invited',
            'email' => $invitation->getEmail(),
            'expiresAt' => $invitation->getExpiresAt()->format(\DateTimeInterface::ATOM),
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_invitations_revoke', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    public function revoke(string $id): JsonResponse
    {
        $invitation = $this->repository->find($id);

        if ($invitation === null) {
            return new JsonResponse(['status' => 'failed', 'error' => 'errors.notFound'], Response::HTTP_NOT_FOUND);
        }

        $this->entityManager->remove($invitation);
        $this->entityManager->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /** Public: the invitee has no account yet. */
    #[Route('/accept/{token}', name: 'api_invitations_inspect', methods: ['GET'])]
    public function inspect(string $token): JsonResponse
    {
        $invitation = $this->invitations->findPendingByToken($token);

        return $invitation === null
            ? new JsonResponse(['valid' => false], Response::HTTP_NOT_FOUND)
            : new JsonResponse(['valid' => true, 'email' => $invitation->getEmail()]);
    }

    #[Route('/accept/{token}', name: 'api_invitations_accept', methods: ['POST'])]
    public function accept(string $token, Request $request): JsonResponse
    {
        $invitation = $this->invitations->findPendingByToken($token);

        if ($invitation === null) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => 'invitations.linkInvalid',
            ], Response::HTTP_NOT_FOUND);
        }

        $payload = $request->toArray();

        $violations = $this->validator->validate($payload, new Assert\Collection([
            'displayName' => [new Assert\NotBlank(), new Assert\Length(min: 2, max: 100)],
            'password' => [new Assert\NotBlank(), new Assert\Length(min: 12, max: 4096)],
        ]));

        if ($violations->count() > 0) {
            $errors = [];

            foreach ($violations as $violation) {
                $errors[trim($violation->getPropertyPath(), '[]')] = $violation->getMessage();
            }

            return new JsonResponse(['status' => 'failed', 'errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->invitations->accept($invitation, $payload['displayName'], $payload['password']);
        } catch (AccountAlreadyExists) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => 'invitations.accountExists',
            ], Response::HTTP_CONFLICT);
        }

        return new JsonResponse(['status' => 'accepted'], Response::HTTP_CREATED);
    }
}
