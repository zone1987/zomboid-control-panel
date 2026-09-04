<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController extends AbstractController
{
    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    public function __invoke(Connection $connection, UserRepository $users): JsonResponse
    {
        $database = 'unreachable';
        $setupComplete = false;

        try {
            $connection->executeQuery('SELECT 1');
            $database = 'ok';
            $setupComplete = $users->count() > 0;
        } catch (\Throwable) {
            // Reported through the payload; the endpoint itself stays up so
            // orchestrators can distinguish "app down" from "database down".
        }

        return new JsonResponse([
            'status' => $database === 'ok' ? 'ok' : 'degraded',
            'database' => $database,
            'setupComplete' => $setupComplete,
            'time' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ], $database === 'ok' ? 200 : 503);
    }
}
