<?php

declare(strict_types=1);

namespace App\Security\Webauthn;

use App\Entity\User;
use App\Entity\WebauthnCredential;
use App\Repository\WebauthnCredentialRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Webauthn\Bundle\Security\Handler\SuccessHandler;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialOptions;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * Names the credential the bundle just stored and returns it to the SPA.
 *
 * The bundle hands this handler only the request, so the credential is
 * located as the newest one on the account rather than by its id.
 */
final readonly class RegistrationSuccessHandler implements SuccessHandler
{
    public function __construct(
        private Security $security,
        private WebauthnCredentialRepository $credentials,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function onSuccess(
        Request $request,
        ?PublicKeyCredential $publicKeyCredential = null,
        ?PublicKeyCredentialOptions $publicKeyCredentialOptions = null,
        ?PublicKeyCredentialUserEntity $userEntity = null,
    ): Response {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => 'passkey.registrationFailed',
            ], Response::HTTP_BAD_REQUEST);
        }

        $credential = $this->credentials->findNewestForUser($user);

        if ($credential instanceof WebauthnCredential) {
            $credential->setName($this->readDeviceName($request));
            $this->entityManager->flush();
        }

        return new JsonResponse([
            'status' => 'registered',
            'credential' => $credential instanceof WebauthnCredential
                ? CredentialPayload::from($credential)
                : null,
        ], Response::HTTP_CREATED);
    }

    private function readDeviceName(Request $request): string
    {
        try {
            $payload = $request->toArray();
        } catch (\Throwable) {
            return 'Passkey';
        }

        $name = $payload['deviceName'] ?? null;

        if (!\is_string($name) || trim($name) === '') {
            return 'Passkey';
        }

        return mb_substr(trim($name), 0, 100);
    }
}
