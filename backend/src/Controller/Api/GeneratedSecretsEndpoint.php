<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Security\Permission\Permission;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The encryption key, for the one deployment shape that generates it.
 *
 * A container that makes its own key leaves nobody with a copy, and a
 * database backup restored without it loses every stored FTP and RCON
 * password. So the key is shown once here, with the instruction to save
 * it -- there is no other way for the operator to obtain it.
 *
 * A key the operator set themselves is never echoed back: they already
 * have it, and returning it would only widen where it exists.
 */
#[Route('/api/settings/generated-secrets')]
#[IsGranted(Permission::EditSettings->value)]
final class GeneratedSecretsEndpoint extends AbstractController
{
    public function __construct(
        private readonly string $generatedSecretsPath,
    ) {
    }

    #[Route('', name: 'api_settings_generated_secrets', methods: ['GET'])]
    public function show(): JsonResponse
    {
        $file = rtrim($this->generatedSecretsPath, '/').'/credentials-key';

        // Checked rather than suppressed: `@` hides the warning from the
        // log but not from a dev environment, which turns it into a 500.
        if (!is_file($file) || !is_readable($file)) {
            return new JsonResponse(['generated' => false, 'key' => null]);
        }

        $key = file_get_contents($file);

        if (!is_string($key) || trim($key) === '') {
            return new JsonResponse(['generated' => false, 'key' => null]);
        }

        return new JsonResponse(['generated' => true, 'key' => trim($key)]);
    }
}
