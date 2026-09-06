<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\AppSetting;
use App\Security\Permission\Permission;
use App\Settings\SettingsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/settings')]
#[IsGranted(Permission::EditSettings->value)]
final class SettingsController extends AbstractController
{
    private const EDITABLE = [
        AppSetting::STEAM_API_KEY,
        AppSetting::GOOGLE_CLIENT_ID,
        AppSetting::GOOGLE_CLIENT_SECRET,
        AppSetting::MAIL_HOST,
        AppSetting::MAIL_PORT,
        AppSetting::MAIL_USERNAME,
        AppSetting::MAIL_PASSWORD,
        AppSetting::MAIL_ENCRYPTION,
        AppSetting::MAIL_FROM_ADDRESS,
        AppSetting::MAIL_FROM_NAME,
        // Still accepted for anyone who would rather paste one, but the
        // interface asks for the individual fields.
        AppSetting::MAILER_DSN,
    ];

    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly UrlGeneratorInterface $urls,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%env(APP_PUBLIC_URL)%')]
        private readonly string $publicUrl,
    ) {
    }

    #[Route('', name: 'api_settings_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $items = [];

        foreach (self::EDITABLE as $name) {
            $secret = \in_array($name, AppSetting::SECRET_KEYS, true);
            $value = $this->settings->get($name);

            $items[$name] = [
                'configured' => $value !== null,
                'fromEnvironment' => $this->settings->isFromEnvironment($name),
                // Secrets are never returned; the interface shows a mask and
                // an empty field means "leave unchanged".
                'value' => $secret ? null : $value,
                'secret' => $secret,
            ];
        }

        return new JsonResponse([
            'items' => $items,
            // Generated server-side: a dev server on another port would
            // otherwise show a redirect URI Google never calls.
            'googleRedirectUri' => $this->googleRedirectUri(),
        ]);
    }

    /**
     * Built from the configured public URL rather than the current request:
     * a dev server on another port would otherwise print a redirect URI
     * Google never calls.
     */
    private function googleRedirectUri(): string
    {
        $path = $this->urls->generate('api_connect_google_check');

        return rtrim($this->publicUrl, '/').$path;
    }

    #[Route('', name: 'api_settings_update', methods: ['PATCH'])]
    public function update(Request $request): JsonResponse
    {
        $payload = $request->toArray();
        $unknown = array_diff(array_keys($payload), self::EDITABLE);

        if ($unknown !== []) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => 'settings.unknownKey',
                'keys' => array_values($unknown),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        foreach ($payload as $name => $value) {
            if ($value !== null && !\is_string($value)) {
                return new JsonResponse([
                    'status' => 'failed',
                    'errors' => [$name => 'validation.invalid'],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $this->settings->set($name, $value);
        }

        return $this->list();
    }

    /**
     * Verifies the Steam key actually works, rather than only that it was
     * typed in.
     */
    #[Route('/mail/test', name: 'api_settings_test_mail', methods: ['POST'])]
    public function testMail(Request $request, \App\Mail\ConfiguredMailer $mailer, #[\Symfony\Component\Security\Http\Attribute\CurrentUser] \App\Entity\User $user): JsonResponse
    {
        $recipient = $request->toArray()['recipient'] ?? $user->getEmail();

        if (!\is_string($recipient) || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            return new JsonResponse([
                'status' => 'failed',
                'errors' => ['recipient' => 'validation.emailInvalid'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $mailer->sendTestMessage($recipient);
        } catch (\App\Mail\MailNotConfigured) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => 'settings.mailNotConfigured',
            ], Response::HTTP_CONFLICT);
        } catch (\Throwable $exception) {
            // The transport's own message is the only useful clue here —
            // wrong port, refused credentials, unreachable host all look
            // the same from outside.
            return new JsonResponse([
                'status' => 'failed',
                'error' => 'settings.mailFailed',
                'detail' => $exception->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }

        return new JsonResponse(['status' => 'sent', 'recipient' => $recipient]);
    }

    #[Route('/mail/deliverability', name: 'api_settings_deliverability', methods: ['GET'])]
    public function deliverability(\App\Mail\DeliverabilityChecker $checker): JsonResponse
    {
        return new JsonResponse($checker->check($this->settings));
    }

    #[Route('/steam/test', name: 'api_settings_test_steam', methods: ['POST'])]
    public function testSteamKey(\App\Security\OAuth\SteamProfileFetcher $profiles): JsonResponse
    {
        if (!$this->settings->isConfigured(AppSetting::STEAM_API_KEY)) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => 'settings.steamKeyMissing',
            ], Response::HTTP_CONFLICT);
        }

        // Valve's own account; a stable, always-present profile to probe with.
        $name = $profiles->fetchPersonaName('76561197960435530');

        return new JsonResponse(
            $name === null
                ? ['status' => 'failed', 'error' => 'settings.steamKeyRejected']
                : ['status' => 'ok', 'sample' => $name],
            $name === null ? Response::HTTP_BAD_GATEWAY : Response::HTTP_OK,
        );
    }
}
