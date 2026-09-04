<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Security\TwoFactor\BackupCodeGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/two-factor')]
final class TwoFactorController extends AbstractController
{
    private const PENDING_SECRET_KEY = 'two_factor.pending_secret';

    public function __construct(
        private readonly TotpAuthenticatorInterface $totp,
        private readonly EntityManagerInterface $entityManager,
        private readonly BackupCodeGenerator $backupCodes,
        private readonly RequestStack $requestStack,
    ) {
    }

    #[Route('/status', name: 'api_2fa_status', methods: ['GET'])]
    public function status(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse([
            'enabled' => $user->isTotpAuthenticationEnabled(),
            'backupCodesRemaining' => $user->getBackupCodeCount(),
        ]);
    }

    /**
     * Produces a secret and keeps it in the session until a valid code
     * proves the authenticator holds it too. Writing it to the account
     * before that would lock the user out of their own second factor.
     */
    #[Route('/setup', name: 'api_2fa_setup', methods: ['POST'])]
    public function setup(#[CurrentUser] User $user): JsonResponse
    {
        if ($user->isTotpAuthenticationEnabled()) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => 'twoFactor.alreadyEnabled',
            ], Response::HTTP_CONFLICT);
        }

        $secret = $this->totp->generateSecret();
        $this->requestStack->getSession()->set(self::PENDING_SECRET_KEY, $secret);

        $probe = clone $user;
        $probe->setTotpSecret($secret);

        return new JsonResponse([
            'secret' => $secret,
            'qrContent' => $this->totp->getQRContent($probe),
        ]);
    }

    #[Route('/activate', name: 'api_2fa_activate', methods: ['POST'])]
    public function activate(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        if ($user->isTotpAuthenticationEnabled()) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => 'twoFactor.alreadyEnabled',
            ], Response::HTTP_CONFLICT);
        }

        $session = $this->requestStack->getSession();
        $secret = $session->get(self::PENDING_SECRET_KEY);

        if (!\is_string($secret)) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => 'twoFactor.setupExpired',
            ], Response::HTTP_CONFLICT);
        }

        $code = $request->toArray()['code'] ?? null;

        if (!\is_string($code) || trim($code) === '') {
            return new JsonResponse([
                'status' => 'failed',
                'errors' => ['code' => 'validation.required'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $probe = clone $user;
        $probe->setTotpSecret($secret);

        if (!$this->totp->checkCode($probe, trim($code))) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => 'auth.invalidCode',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user->setTotpSecret($secret);
        $codes = $this->backupCodes->generateFor($user);
        $this->entityManager->flush();

        $session->remove(self::PENDING_SECRET_KEY);

        return new JsonResponse([
            'status' => 'enabled',
            // Shown once; only hashes are kept.
            'backupCodes' => $codes,
        ]);
    }

    #[Route('/backup-codes', name: 'api_2fa_regenerate_codes', methods: ['POST'])]
    public function regenerateBackupCodes(Request $request, #[CurrentUser] User $user, UserPasswordHasherInterface $hasher): JsonResponse
    {
        if (!$user->isTotpAuthenticationEnabled()) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => 'twoFactor.notEnabled',
            ], Response::HTTP_CONFLICT);
        }

        if (!$this->confirmsIdentity($request, $user, $hasher)) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => 'auth.invalidCredentials',
            ], Response::HTTP_FORBIDDEN);
        }

        $codes = $this->backupCodes->generateFor($user);
        $this->entityManager->flush();

        return new JsonResponse(['status' => 'regenerated', 'backupCodes' => $codes]);
    }

    #[Route('', name: 'api_2fa_disable', methods: ['DELETE'])]
    public function disable(Request $request, #[CurrentUser] User $user, UserPasswordHasherInterface $hasher): JsonResponse
    {
        if (!$user->isTotpAuthenticationEnabled()) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => 'twoFactor.notEnabled',
            ], Response::HTTP_CONFLICT);
        }

        // Turning off a second factor is exactly what an attacker on a
        // borrowed session would try first.
        if (!$this->confirmsIdentity($request, $user, $hasher)) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => 'auth.invalidCredentials',
            ], Response::HTTP_FORBIDDEN);
        }

        $user->setTotpSecret(null);
        $this->entityManager->flush();

        return new JsonResponse(['status' => 'disabled']);
    }

    private function confirmsIdentity(Request $request, User $user, UserPasswordHasherInterface $hasher): bool
    {
        $password = $request->toArray()['password'] ?? null;

        if ($user->getPassword() === null) {
            // Passwordless accounts confirm with a fresh authenticator code.
            $code = $request->toArray()['code'] ?? null;

            return \is_string($code) && $this->totp->checkCode($user, trim($code));
        }

        return \is_string($password) && $hasher->isPasswordValid($user, $password);
    }
}
