<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class SetupController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('/api/setup/status', name: 'api_setup_status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        return new JsonResponse(['setupComplete' => $this->users->count() > 0]);
    }

    #[Route('/api/setup', name: 'api_setup_complete', methods: ['POST'])]
    public function complete(Request $request): JsonResponse
    {
        // The wizard is open only while no account exists at all. Once one
        // does, this endpoint is permanently closed.
        if ($this->users->count() > 0) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => 'setup.alreadyCompleted',
            ], Response::HTTP_CONFLICT);
        }

        $payload = $request->toArray();

        $violations = $this->validator->validate($payload, new Assert\Collection([
            'email' => [new Assert\NotBlank(), new Assert\Email()],
            'displayName' => [new Assert\NotBlank(), new Assert\Length(min: 2, max: 100)],
            'password' => [new Assert\NotBlank(), new Assert\Length(min: 12, max: 4096)],
        ]));

        if ($violations->count() > 0) {
            $errors = [];

            foreach ($violations as $violation) {
                $errors[trim($violation->getPropertyPath(), '[]')] = $violation->getMessage();
            }

            return new JsonResponse([
                'status' => 'failed',
                'errors' => $errors,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = new User($payload['email'], $payload['displayName']);
        $user->setRoles([User::ROLE_ADMIN, User::ROLE_SERVER_ADMIN]);
        $user->setPassword($this->passwordHasher->hashPassword($user, $payload['password']));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return new JsonResponse(['status' => 'created'], Response::HTTP_CREATED);
    }
}
