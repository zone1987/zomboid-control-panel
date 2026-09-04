<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Account\AccountManager;
use App\Account\LastAdministrator;
use App\Account\SelfModification;
use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/accounts')]
#[IsGranted('ROLE_ADMIN')]
final class AccountController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AccountManager $accounts,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('', name: 'api_accounts_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $actor): JsonResponse
    {
        return new JsonResponse([
            'items' => array_map(
                fn (User $user): array => $this->present($user, $actor),
                $this->users->findAllForManagement(),
            ),
            'assignableRoles' => AccountManager::ASSIGNABLE_ROLES,
        ]);
    }

    #[Route('/{id}', name: 'api_accounts_update', methods: ['PATCH'])]
    public function update(string $id, Request $request, #[CurrentUser] User $actor): JsonResponse
    {
        $subject = $this->find($id);

        if (!$subject instanceof User) {
            return $this->notFound();
        }

        $payload = $this->payloadOf($request);

        $violations = $this->validate($payload);

        if ($violations !== []) {
            return new JsonResponse(
                ['status' => 'failed', 'errors' => $violations],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            if (\array_key_exists('displayName', $payload) || \array_key_exists('email', $payload)) {
                $this->accounts->updateProfile(
                    $subject,
                    $this->stringOrNull($payload['displayName'] ?? null),
                    $this->stringOrNull($payload['email'] ?? null),
                );
            }

            if (\array_key_exists('roles', $payload) && \is_array($payload['roles'])) {
                $this->accounts->setRoles($subject, array_values(array_filter($payload['roles'], \is_string(...))), $actor);
            }

            if (\array_key_exists('active', $payload)) {
                $this->accounts->setActive($subject, (bool) $payload['active'], $actor);
            }

            if (\array_key_exists('password', $payload) && \is_string($payload['password']) && $payload['password'] !== '') {
                $this->accounts->setPassword($subject, $payload['password']);
            }
        } catch (SelfModification|LastAdministrator $exception) {
            return new JsonResponse(
                ['status' => 'failed', 'error' => $exception->messageKey()],
                Response::HTTP_CONFLICT,
            );
        }

        return new JsonResponse($this->present($subject, $actor));
    }

    #[Route('/{id}', name: 'api_accounts_delete', methods: ['DELETE'])]
    public function delete(string $id, #[CurrentUser] User $actor): JsonResponse
    {
        $subject = $this->find($id);

        if (!$subject instanceof User) {
            return $this->notFound();
        }

        try {
            $this->accounts->delete($subject, $actor);
        } catch (SelfModification|LastAdministrator $exception) {
            return new JsonResponse(
                ['status' => 'failed', 'error' => $exception->messageKey()],
                Response::HTTP_CONFLICT,
            );
        }

        return new JsonResponse(['status' => 'deleted']);
    }

    /** @return array<string, mixed> */
    private function present(User $user, User $actor): array
    {
        return [
            'id' => $user->getId()->toRfc4122(),
            'email' => $user->getEmail(),
            'displayName' => $user->getDisplayName(),
            'roles' => array_values(array_intersect(AccountManager::ASSIGNABLE_ROLES, $user->getRoles())),
            'active' => $user->isActive(),
            'locale' => $user->getLocale(),
            'hasPassword' => $user->getPassword() !== null,
            'twoFactorEnabled' => $user->isTotpAuthenticationEnabled(),
            'passkeyCount' => $user->getWebauthnCredentials()->count(),
            'identities' => array_values(array_map(
                static fn ($identity): string => $identity->getProvider(),
                $user->getOauthIdentities()->toArray(),
            )),
            'createdAt' => $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'lastLoginAt' => $user->getLastLoginAt()?->format(\DateTimeInterface::ATOM),
            'self' => $user->getId()->equals($actor->getId()),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, string>
     */
    private function validate(array $payload): array
    {
        $constraints = [
            'email' => [new Assert\Email(message: 'validation.emailInvalid'), new Assert\Length(max: 180)],
            'displayName' => [new Assert\NotBlank(message: 'validation.required'), new Assert\Length(max: 100)],
            'password' => [new Assert\Length(min: 12, minMessage: 'validation.passwordTooShort')],
        ];

        $errors = [];

        foreach ($constraints as $field => $rules) {
            if (!\array_key_exists($field, $payload) || !\is_string($payload[$field]) || $payload[$field] === '') {
                continue;
            }

            $violations = $this->validator->validate($payload[$field], $rules);

            if (\count($violations) > 0) {
                $errors[$field] = (string) $violations[0]->getMessage();
            }
        }

        return $errors;
    }

    private function find(string $id): ?User
    {
        return Uuid::isValid($id) ? $this->users->find(Uuid::fromString($id)) : null;
    }

    /** @return array<string, mixed> */
    private function payloadOf(Request $request): array
    {
        try {
            return $request->toArray();
        } catch (\Throwable) {
            return [];
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        return \is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(['status' => 'failed', 'error' => 'errors.notFound'], Response::HTTP_NOT_FOUND);
    }
}
