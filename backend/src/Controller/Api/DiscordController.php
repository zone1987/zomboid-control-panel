<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\AppSetting;
use App\Entity\DiscordCommandRight;
use App\Entity\DiscordConfig;
use App\Entity\DiscordNotification;
use App\Entity\GameServer;
use App\Repository\DiscordCommandRightRepository;
use App\Repository\DiscordNotificationRepository;
use App\Repository\GameServerRepository;
use App\Security\Permission\Permission;
use App\Server\Discord\CommandCapabilities;
use App\Server\Discord\CommandCatalogue;
use App\Server\Discord\DiscordClientInterface;
use App\Server\Discord\DiscordException;
use App\Server\Discord\DiscordMessage;
use App\Server\Discord\MessageTemplate;
use App\Server\Discord\NotifiableEvents;
use App\Settings\SettingsProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Setting up the Discord link for one server. */
#[Route('/api/servers/{id}/discord')]
#[IsGranted(Permission::ManageDiscord->value)]
final class DiscordController extends AbstractController
{
    public function __construct(
        private readonly GameServerRepository $servers,
        private readonly DiscordNotificationRepository $notifications,
        private readonly DiscordCommandRightRepository $rights,
        private readonly DiscordClientInterface $discord,
        private readonly SettingsProvider $settings,
        private readonly EntityManagerInterface $entityManager,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%env(APP_PUBLIC_URL)%')]
        private readonly string $publicUrl,
    ) {
    }

    /** Everything the interface needs to draw the page. */
    #[Route('', name: 'api_discord_show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $server = $this->servers->find($id);

        if (!$server instanceof GameServer) {
            return $this->notFound();
        }

        $config = $server->getDiscordConfig();
        $notifications = $this->notifications->forServer($id);
        $rights = $this->rights->forServer($id);

        return new JsonResponse([
            // Whether the panel could talk to Discord at all, which is
            // the first thing to know and the first thing to fix.
            'tokenConfigured' => $this->settings->isConfigured(AppSetting::DISCORD_BOT_TOKEN),
            'applicationId' => $this->settings->get(AppSetting::DISCORD_APPLICATION_ID) ?? '',
            'publicKeyConfigured' => $this->settings->isConfigured(AppSetting::DISCORD_PUBLIC_KEY),
            // Slash commands need Discord to reach *us*; notifications
            // and outbound chat do not. Two different capabilities, and
            // collapsing them would say the whole integration is broken
            // when most of it works.
            'commandsReachable' => $this->isPubliclyReachable(),
            'guildId' => $config?->getGuildId() ?? '',
            'chatChannelId' => $config?->getChatChannelId(),
            'chatScope' => $config?->getChatScope() ?? DiscordConfig::SCOPE_GENERAL_ONLY,
            'relayIntoGame' => $config?->relaysIntoGame() ?? false,
            'commandsEnabled' => $config?->areCommandsEnabled() ?? true,
            'events' => $this->describeEvents($notifications),
            'commands' => $this->describeCommands($rights),
        ]);
    }

    /** The guild's text channels, so nobody has to paste an id. */
    #[Route('/channels', name: 'api_discord_channels', methods: ['GET'])]
    public function channels(string $id): JsonResponse
    {
        $server = $this->servers->find($id);

        if (!$server instanceof GameServer) {
            return $this->notFound();
        }

        $guildId = $server->getDiscordConfig()?->getGuildId();

        if ($guildId === null) {
            return new JsonResponse(['status' => 'failed', 'error' => 'discord.noGuild'], Response::HTTP_CONFLICT);
        }

        try {
            return new JsonResponse(['channels' => $this->discord->channels($guildId)]);
        } catch (DiscordException $exception) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => $exception->messageKey(),
            ], Response::HTTP_BAD_GATEWAY);
        }
    }

    /** The guild's roles, so a command is granted by name. */
    #[Route('/roles', name: 'api_discord_roles', methods: ['GET'])]
    public function roles(string $id): JsonResponse
    {
        $server = $this->servers->find($id);

        if (!$server instanceof GameServer) {
            return $this->notFound();
        }

        $guildId = $server->getDiscordConfig()?->getGuildId();

        if ($guildId === null) {
            return new JsonResponse(['status' => 'failed', 'error' => 'discord.noGuild'], Response::HTTP_CONFLICT);
        }

        try {
            return new JsonResponse(['roles' => $this->discord->roles($guildId)]);
        } catch (DiscordException $exception) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => $exception->messageKey(),
            ], Response::HTTP_BAD_GATEWAY);
        }
    }

    /** Which guild this server belongs to, and how chat is handled. */
    #[Route('', name: 'api_discord_update', methods: ['PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $server = $this->servers->find($id);

        if (!$server instanceof GameServer) {
            return $this->notFound();
        }

        $payload = $request->toArray();
        $config = $server->getDiscordConfig();

        if (isset($payload['guildId'])) {
            $guildId = trim((string) $payload['guildId']);

            if ($guildId === '') {
                // Removing the guild unlinks the server: the rows for
                // channels and rights stay, so relinking the same guild
                // does not mean setting everything up again.
                if ($config !== null) {
                    $this->entityManager->remove($config);
                    $server->setDiscordConfig(null);
                }

                $this->entityManager->flush();

                return new JsonResponse(['status' => 'unlinked']);
            }

            if (preg_match('/^\d{15,25}$/', $guildId) !== 1) {
                return new JsonResponse([
                    'status' => 'failed',
                    'errors' => ['guildId' => 'validation.invalid'],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            if ($config === null) {
                $config = new DiscordConfig($server, $guildId);
                $this->entityManager->persist($config);
            } else {
                $config->setGuildId($guildId);
            }
        }

        if ($config === null) {
            return new JsonResponse(['status' => 'failed', 'error' => 'discord.noGuild'], Response::HTTP_CONFLICT);
        }

        if (array_key_exists('chatChannelId', $payload)) {
            $config->setChatChannelId(
                is_string($payload['chatChannelId']) ? trim($payload['chatChannelId']) : null,
            );
        }

        if (isset($payload['chatScope']) && in_array($payload['chatScope'], DiscordConfig::SCOPES, true)) {
            $config->setChatScope($payload['chatScope']);
        }

        if (isset($payload['relayIntoGame'])) {
            $config->setRelayIntoGame($payload['relayIntoGame'] === true);
        }

        if (isset($payload['commandsEnabled'])) {
            $config->setCommandsEnabled($payload['commandsEnabled'] === true);
        }

        $this->entityManager->flush();

        return new JsonResponse(['status' => 'saved']);
    }

    /** One event's switch, channel and wording. */
    #[Route('/events/{type}', name: 'api_discord_event', methods: ['PUT'], requirements: ['type' => '[A-Za-z._]+'])]
    public function event(string $id, string $type, Request $request): JsonResponse
    {
        $server = $this->servers->find($id);

        if (!$server instanceof GameServer) {
            return $this->notFound();
        }

        if (NotifiableEvents::defaultTemplate($type) === null) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => 'discord.unknownEvent',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $payload = $request->toArray();
        $setting = $this->notifications->forEvent($id, $type);

        if ($setting === null) {
            $setting = new DiscordNotification($server, $type);
            $this->entityManager->persist($setting);
        }

        if (isset($payload['enabled'])) {
            $setting->setEnabled($payload['enabled'] === true);
        }

        if (array_key_exists('channelId', $payload)) {
            $setting->setChannelId(is_string($payload['channelId']) ? trim($payload['channelId']) : null);
        }

        if (array_key_exists('template', $payload)) {
            $template = is_string($payload['template']) ? $payload['template'] : null;

            // A typo is reported now rather than discovered in a channel
            // weeks later -- but it is a warning, not a refusal: an
            // unknown token stays visible and harms nothing.
            $unknown = $template === null
                ? []
                : (new MessageTemplate())->unknownTokens($template, NotifiableEvents::tokensFor($type));

            $setting->setTemplate($template);
            $this->entityManager->flush();

            return new JsonResponse(['status' => 'saved', 'unknownTokens' => $unknown]);
        }

        $this->entityManager->flush();

        return new JsonResponse(['status' => 'saved', 'unknownTokens' => []]);
    }

    /** Sends the wording being edited, to the channel it would use. */
    #[Route('/events/{type}/test', name: 'api_discord_event_test', methods: ['POST'], requirements: ['type' => '[A-Za-z._]+'])]
    public function testEvent(string $id, string $type, Request $request): JsonResponse
    {
        $server = $this->servers->find($id);

        if (!$server instanceof GameServer) {
            return $this->notFound();
        }

        $payload = $request->toArray();
        $setting = $this->notifications->forEvent($id, $type);
        $channelId = is_string($payload['channelId'] ?? null)
            ? trim($payload['channelId'])
            : $setting?->getChannelId();

        if ($channelId === null || $channelId === '') {
            return new JsonResponse([
                'status' => 'failed',
                'error' => 'discord.noChannel',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $template = is_string($payload['template'] ?? null) && trim($payload['template']) !== ''
            ? $payload['template']
            : ($setting?->getTemplate() ?? NotifiableEvents::defaultTemplate($type) ?? '');

        // Example values, so the operator sees the shape of the real
        // thing rather than a sentence full of empty gaps.
        $content = (new MessageTemplate())->render($template, [
            'server' => $server->getName(),
            'player' => 'Beispielspieler',
            'admin' => 'Beispiel-Admin',
            'reason' => 'zur Probe',
            'detail' => 'Testnachricht',
            'action' => 'test',
            'input.version' => '0.21.0',
        ]);

        try {
            $this->discord->sendMessage($channelId, new DiscordMessage($content));
        } catch (DiscordException $exception) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => $exception->messageKey(),
            ], Response::HTTP_BAD_GATEWAY);
        }

        return new JsonResponse(['status' => 'sent', 'content' => $content]);
    }

    /** Which Discord roles may run one subcommand. */
    #[Route('/commands/{command}', name: 'api_discord_command', methods: ['PUT'], requirements: ['command' => '[a-zä-ü.]+'])]
    public function command(string $id, string $command, Request $request): JsonResponse
    {
        $server = $this->servers->find($id);

        if (!$server instanceof GameServer) {
            return $this->notFound();
        }

        if (!in_array($command, CommandCatalogue::names(), true)) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => 'discord.unknownCommand',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $payload = $request->toArray();
        $right = $this->rights->forCommand($id, $command);

        if ($right === null) {
            $right = new DiscordCommandRight($server, $command);
            $this->entityManager->persist($right);
        }

        $roles = [];

        foreach ($payload['roleIds'] ?? [] as $roleId) {
            if (is_string($roleId)) {
                $roles[] = $roleId;
            }
        }

        $right->setRoleIds($roles);
        $this->entityManager->flush();

        return new JsonResponse(['status' => 'saved', 'roleIds' => $right->getRoleIds()]);
    }

    /** Puts the catalogue into the guild, replacing what is there. */
    #[Route('/register', name: 'api_discord_register', methods: ['POST'])]
    public function register(string $id): JsonResponse
    {
        $server = $this->servers->find($id);

        if (!$server instanceof GameServer) {
            return $this->notFound();
        }

        $guildId = $server->getDiscordConfig()?->getGuildId();
        $applicationId = $this->settings->get(AppSetting::DISCORD_APPLICATION_ID);

        if ($guildId === null) {
            return new JsonResponse(['status' => 'failed', 'error' => 'discord.noGuild'], Response::HTTP_CONFLICT);
        }

        if ($applicationId === null || trim($applicationId) === '') {
            return new JsonResponse(['status' => 'failed', 'error' => 'discord.noApplicationId'], Response::HTTP_CONFLICT);
        }

        try {
            $this->discord->registerCommands(trim($applicationId), $guildId, CommandCatalogue::all());
        } catch (DiscordException $exception) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => $exception->messageKey(),
            ], Response::HTTP_BAD_GATEWAY);
        }

        return new JsonResponse(['status' => 'registered', 'count' => count(CommandCatalogue::all())]);
    }

    /**
     * @param array<string, DiscordNotification> $settings
     *
     * @return list<array<string, mixed>>
     */
    private function describeEvents(array $settings): array
    {
        $described = [];

        foreach (NotifiableEvents::all() as $type) {
            $setting = $settings[$type] ?? null;

            $described[] = [
                'type' => $type,
                'adminAction' => NotifiableEvents::isAdminAction($type),
                'enabled' => $setting?->isEnabled() ?? false,
                'active' => $setting?->isActive() ?? false,
                'channelId' => $setting?->getChannelId(),
                'template' => $setting?->getTemplate(),
                'defaultTemplate' => NotifiableEvents::defaultTemplate($type),
                'tokens' => NotifiableEvents::tokensFor($type),
            ];
        }

        return $described;
    }

    /**
     * @param array<string, DiscordCommandRight> $rights
     *
     * @return list<array<string, mixed>>
     */
    private function describeCommands(array $rights): array
    {
        $described = [];

        foreach (CommandCatalogue::names() as $name) {
            [$command, $subcommand] = explode('.', $name, 2);

            $described[] = [
                'name' => $name,
                'command' => $command,
                'subcommand' => $subcommand,
                // What the same act costs in the panel, shown so the
                // operator can see a command is not a free pass.
                'permission' => CommandCapabilities::of($command, $subcommand)?->value,
                // `?->` does not help with a missing *key*, only with a
                // null value: a command nobody has configured has no
                // row at all, which is the normal case on a fresh setup.
                'roleIds' => ($rights[$name] ?? null)?->getRoleIds() ?? [],
            ];
        }

        return $described;
    }

    /**
     * Whether Discord could call this panel.
     *
     * Duplicated deliberately from SettingsController rather than
     * shared: the two answer different questions of the same fact, and
     * a shared helper would have to live somewhere neither controller
     * owns. If a third caller appears, extract it then.
     */
    private function isPubliclyReachable(): bool
    {
        $host = parse_url($this->publicUrl, PHP_URL_HOST);

        if (!is_string($host) || $host === '' || !str_contains($host, '.')) {
            return false;
        }

        foreach (['.localhost', '.local', '.test', '.internal', '.ddev.site', '.example'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return false;
            }
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) !== false;
        }

        return true;
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(['status' => 'failed', 'error' => 'errors.notFound'], Response::HTTP_NOT_FOUND);
    }
}
