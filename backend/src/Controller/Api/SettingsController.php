<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\AppSetting;
use App\Server\Cache\ServerCacheCleaner;
use App\Security\Permission\Permission;
use App\Panel\DeployProbe;
use App\Panel\DeployProbeVerdict;
use App\Panel\DeployTrigger;
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
    /**
     * Below this a purge would delete players who are merely on holiday.
     * Empty still means off, which is the default.
     */
    private const RETENTION_MINIMUM_DAYS = 7;

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
        AppSetting::PLAYER_RETENTION_DAYS,
        // Panel-wide rather than per server: one Discord application
        // serves every guild it is invited to.
        AppSetting::DISCORD_BOT_TOKEN,
        AppSetting::DISCORD_APPLICATION_ID,
        AppSetting::DISCORD_PUBLIC_KEY,
        // The panel asks its own host to pull a new release; see
        // DeployTrigger for why the call belongs here rather than in CI.
        AppSetting::DEPLOY_WEBHOOK_URL,
        AppSetting::DEPLOY_WEBHOOK_TOKEN,
        AppSetting::DEPLOY_ON_RELEASE,
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
            // Both have to be registered with Google: signing in and
            // linking start separate flows, and Google refuses a redirect
            // uri it was not given.
            'googleLinkRedirectUri' => $this->absoluteUrl('api_connect_google_link'),
            // Discord will not accept an application until this URL
            // answers its signed probe, so the operator needs it to
            // hand and should not have to assemble it themselves.
            'discordInteractionUrl' => $this->discordInteractionUrl(),
            // False for a development address: Discord calls the panel,
            // so it has to be reachable from the internet.
            'discordReachable' => $this->isPubliclyReachable(),
        ]);
    }

    /**
     * Built from the configured public URL rather than the current request:
     * a dev server on another port would otherwise print a redirect URI
     * Google never calls.
     */
    private function googleRedirectUri(): string
    {
        return $this->absoluteUrl('api_connect_google_check');
    }

    /** Built from APP_PUBLIC_URL, never from the request. */
    private function absoluteUrl(string $route): string
    {
        $path = $this->urls->generate($route);

        return rtrim($this->publicUrl, '/').$path;
    }

    /**
     * Where Discord posts its interactions.
     *
     * Built from the panel's public URL rather than from the request,
     * for the same reason as the Google one: behind a proxy the request
     * host is the container's, which Discord could never reach.
     */
    private function discordInteractionUrl(): string
    {
        return rtrim($this->publicUrl, '/').$this->urls->generate('api_discord_interactions');
    }

    /**
     * Whether Discord could reach this panel at all.
     *
     * Discord calls *us*, from its own servers — so a development
     * address is a dead end no amount of correct configuration fixes.
     * Saying so is the difference between "the application is not
     * responding" in a Discord channel and knowing why: the request
     * never arrived, and never could.
     */
    private function isPubliclyReachable(): bool
    {
        $host = parse_url($this->publicUrl, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            return false;
        }

        // A hostname with no dot cannot be resolved from outside, and
        // these suffixes and ranges are by definition local.
        if (!str_contains($host, '.')) {
            return false;
        }

        foreach (['.localhost', '.local', '.test', '.internal', '.ddev.site', '.example'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return false;
            }
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            // A public IP is reachable; a private or reserved one is not.
            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) !== false;
        }

        return true;
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

            // A retention horizon deletes data when it comes round, so a
            // value that does not mean what the operator thinks it means
            // is refused here rather than silently read as "off".
            if (
                $name === AppSetting::PLAYER_RETENTION_DAYS
                && \is_string($value)
                && trim($value) !== ''
                && (!ctype_digit(trim($value)) || (int) trim($value) < self::RETENTION_MINIMUM_DAYS)
            ) {
                return new JsonResponse([
                    'status' => 'failed',
                    'errors' => [$name => 'settings.retentionTooShort'],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $this->settings->set($name, $value === null ? null : (trim($value) === '' ? null : $value));
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

    /**
     * Whether the bot token works, and who it belongs to.
     *
     * Asking Discord rather than checking the shape: a token that looks
     * right and has been revoked is the case worth catching, and the
     * bot's own name coming back is proof the operator pasted the right
     * application's token rather than another one.
     */
    #[Route('/discord/test', name: 'api_settings_test_discord', methods: ['POST'])]
    public function testDiscordToken(\App\Server\Discord\DiscordClientInterface $discord): JsonResponse
    {
        if (!$this->settings->isConfigured(AppSetting::DISCORD_BOT_TOKEN)) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => 'discord.noToken',
            ], Response::HTTP_CONFLICT);
        }

        try {
            $bot = $discord->self();
        } catch (\App\Server\Discord\DiscordException $exception) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => $exception->messageKey(),
            ], Response::HTTP_BAD_GATEWAY);
        }

        return new JsonResponse(['status' => 'ok', 'bot' => $bot['username'], 'id' => $bot['id']]);
    }

    /**
     * Fires the deploy hook for real.
     *
     * There is no way to ask a platform "would this work" -- so the test
     * button does the thing, and says so before it is pressed. On a
     * panel already running the current release the deployment is a
     * restart, which is the honest cost of finding out.
     */
    #[Route('/deploy/test', name: 'api_settings_test_deploy', methods: ['POST'])]
    public function testDeployHook(DeployTrigger $deployer): JsonResponse
    {
        $outcome = $deployer->fire();

        return new JsonResponse(
            $outcome->toArray() + ['status' => $outcome->succeeded() ? 'ok' : 'failed'],
            match ($outcome->state) {
                'queued' => Response::HTTP_OK,
                'notConfigured' => Response::HTTP_CONFLICT,
                default => Response::HTTP_BAD_GATEWAY,
            },
        );
    }

    /** Reads the platform; starts nothing. */
    #[Route('/deploy/probe', name: 'api_settings_probe_deploy', methods: ['POST'])]
    public function probeDeploy(DeployProbe $probe): JsonResponse
    {
        $verdict = $probe->probe();

        return new JsonResponse(
            $verdict->toArray() + ['status' => $verdict->looksReady() ? 'ok' : 'failed'],
            match ($verdict->state) {
                DeployProbeVerdict::READY, DeployProbeVerdict::NO_READ_PERMISSION => Response::HTTP_OK,
                DeployProbeVerdict::NOT_CONFIGURED, DeployProbeVerdict::NO_UUID => Response::HTTP_CONFLICT,
                default => Response::HTTP_BAD_GATEWAY,
            },
        );
    }

    /**
     * How far a running deployment has got.
     *
     * A GET: the browser asks repeatedly while the panel restarts, and
     * nothing about asking changes anything.
     */
    #[Route('/deploy/status/{deploymentUuid}', name: 'api_settings_deploy_status', methods: ['GET'])]
    public function deployStatus(string $deploymentUuid, DeployProbe $probe): JsonResponse
    {
        $status = $probe->statusOf($deploymentUuid);

        return new JsonResponse($status->toArray());
    }

    #[Route('/cache', name: 'api_settings_clear_cache', methods: ['POST'])]
    public function clearCache(ServerCacheCleaner $cleaner): JsonResponse
    {
        $verdict = $cleaner->clear();

        return new JsonResponse(
            $verdict->toArray() + ['status' => $verdict->succeeded() ? 'ok' : 'failed'],
            $verdict->succeeded() ? Response::HTTP_OK : Response::HTTP_BAD_GATEWAY,
        );
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
