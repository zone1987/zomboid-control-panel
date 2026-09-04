<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Role;
use App\Repository\RoleRepository;
use App\Security\Permission\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/roles')]
#[IsGranted('ROLE_ADMIN')]
final class RoleController extends AbstractController
{
    public function __construct(
        private readonly RoleRepository $roles,
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('', name: 'api_roles_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $this->roles->ensureBuiltIns();

        return new JsonResponse([
            'items' => array_map(self::present(...), $this->roles->ordered()),
            'permissions' => self::catalogue(),
        ]);
    }

    #[Route('', name: 'api_roles_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $payload = $this->payloadOf($request);
        $label = trim(\is_string($payload['label'] ?? null) ? $payload['label'] : '');

        if ($label === '') {
            return $this->invalid(['label' => 'validation.required']);
        }

        $role = new Role(self::slugify($label), $label, self::permissionsIn($payload));

        if ($this->roles->byName($role->getName()) !== null) {
            return $this->invalid(['label' => 'role.name.already_used']);
        }

        $errors = $this->validator->validate($role);

        if (\count($errors) > 0) {
            return $this->invalid(['label' => (string) $errors->get(0)->getMessage()]);
        }

        $this->entityManager->persist($role);
        $this->entityManager->flush();

        return new JsonResponse(self::present($role), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_roles_update', methods: ['PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $role = $this->roles->find($id);

        if (!$role instanceof Role) {
            return $this->notFound();
        }

        $payload = $this->payloadOf($request);

        if (\is_string($payload['label'] ?? null) && trim($payload['label']) !== '') {
            $label = trim($payload['label']);
            $wanted = self::slugify($label);
            $clash = $this->roles->byName($wanted);

            if ($clash !== null && $clash !== $role) {
                return $this->invalid(['label' => 'role.name.already_used']);
            }

            $role->setLabel($label);
            $role->setName($wanted);
        }

        if (\array_key_exists('permissions', $payload)) {
            $role->setPermissions(self::permissionsIn($payload));
        }

        $this->entityManager->flush();

        return new JsonResponse(self::present($role));
    }

    /**
     * A built-in role stays: without it an installation could remove the
     * only way back into its own administration.
     */
    #[Route('/{id}', name: 'api_roles_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $role = $this->roles->find($id);

        if (!$role instanceof Role) {
            return $this->notFound();
        }

        if ($role->isBuiltIn()) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => 'roles.builtInCannotBeDeleted',
            ], Response::HTTP_CONFLICT);
        }

        $this->entityManager->remove($role);
        $this->entityManager->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /** @return array<string, mixed> */
    private static function present(Role $role): array
    {
        return [
            'id' => $role->getId()->toRfc4122(),
            'name' => $role->getName(),
            'label' => $role->getLabel(),
            'permissions' => $role->getPermissionNames(),
            'builtIn' => $role->isBuiltIn(),
        ];
    }

    /** @return array<string, list<array{name: string, sensitive: bool}>> */
    private static function catalogue(): array
    {
        $grouped = [];

        foreach (Permission::grouped() as $group => $permissions) {
            $grouped[$group] = array_map(
                static fn (Permission $p): array => ['name' => $p->value, 'sensitive' => $p->isSensitive()],
                $permissions,
            );
        }

        return $grouped;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<Permission>
     */
    private static function permissionsIn(array $payload): array
    {
        $given = $payload['permissions'] ?? [];

        if (!\is_array($given)) {
            return [];
        }

        $permissions = [];

        foreach ($given as $value) {
            $permission = \is_string($value) ? Permission::tryFrom($value) : null;

            if ($permission !== null) {
                $permissions[] = $permission;
            }
        }

        return $permissions;
    }

    /** The name is derived so an operator never has to invent an identifier. */
    private static function slugify(string $label): string
    {
        $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $label) ?? '');

        return trim($slug, '-') ?: 'role';
    }

    /** @param array<string, string> $errors */
    private function invalid(array $errors): JsonResponse
    {
        return new JsonResponse(
            ['status' => 'failed', 'errors' => $errors],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
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

    private function notFound(): JsonResponse
    {
        return new JsonResponse(['status' => 'failed', 'error' => 'errors.notFound'], Response::HTTP_NOT_FOUND);
    }
}
