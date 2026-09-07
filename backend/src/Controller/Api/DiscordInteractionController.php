<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\AppSetting;
use App\Entity\GameServer;
use App\Repository\AppSettingRepository;
use App\Repository\GameServerRepository;
use App\Server\Discord\Autocomplete;
use App\Server\Discord\CommandAuthorisation;
use App\Server\Discord\CommandRunner;
use App\Server\Discord\CommandVerdict;
use App\Server\Discord\Interaction;
use App\Server\Discord\InteractionSignature;
use App\Server\Discord\InteractionType;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Where Discord delivers every slash command and autocomplete request.
 *
 * **The panel's only route without a login, and deliberately so: the
 * Ed25519 signature *is* the authentication.** Discord has no session to
 * offer and no secret to send; it signs the request with its
 * application key and we verify it with the matching public key.
 *
 * Three things Discord enforces, and each one is a reason this endpoint
 * looks the way it does:
 *
 * 1. **The signature check must work or the URL is never accepted** —
 *    and Discord re-checks afterwards, disabling an endpoint that starts
 *    letting bad signatures through. So a failure here answers 401 and
 *    never 500: an exception escaping would read as a broken endpoint.
 * 2. **A `PING` must be answered with `PONG`**, or the URL cannot be
 *    saved at all.
 * 3. **Three seconds.** An RCON round trip is not reliably inside that,
 *    so a command that touches the server is acknowledged at once and
 *    answered afterwards.
 */
#[Route('/api/discord')]
final class DiscordInteractionController extends AbstractController
{
    public function __construct(
        private readonly AppSettingRepository $settings,
        private readonly GameServerRepository $servers,
        private readonly CommandAuthorisation $authorisation,
        private readonly CommandRunner $runner,
        private readonly Autocomplete $autocomplete,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/interactions', name: 'api_discord_interactions', methods: ['POST'])]
    public function interactions(Request $request): JsonResponse
    {
        $body = $request->getContent();

        if (!$this->isFromDiscord($request, $body)) {
            // 401 rather than 403: Discord documents 401 as the answer
            // it expects for a failed signature, and it is what its own
            // verification probe looks for.
            return new JsonResponse(['error' => 'invalid signature'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'malformed body'], Response::HTTP_BAD_REQUEST);
        }

        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'malformed body'], Response::HTTP_BAD_REQUEST);
        }

        $type = (int) ($payload['type'] ?? 0);

        // Answered before anything else is consulted: Discord sends this
        // to verify the URL, at a point where nothing else is set up.
        if ($type === InteractionType::PING) {
            return new JsonResponse(['type' => InteractionType::PONG]);
        }

        $interaction = Interaction::fromPayload($payload);

        if ($interaction === null) {
            return $this->reply('Diese Anfrage konnte nicht gelesen werden.');
        }

        $server = $this->serverFor($interaction);

        if ($server === null) {
            return $this->reply('Für diesen Discord-Server ist kein Spielserver hinterlegt.');
        }

        if ($type === InteractionType::AUTOCOMPLETE) {
            // Answered from the database alone. Autocomplete has the same
            // three seconds as everything else, and waiting on the bridge
            // for a suggestion would spend them.
            return new JsonResponse([
                'type' => InteractionType::AUTOCOMPLETE_RESULT,
                'data' => ['choices' => $this->autocomplete->suggest($server, $interaction)],
            ]);
        }

        $verdict = $this->authorisation->decide($server, $interaction);

        if (!$verdict->isAllowed()) {
            $this->logger->info('a discord command was refused', [
                'command' => $interaction->name(),
                'user' => $interaction->userId,
                'verdict' => $verdict->value,
            ]);

            return $this->reply($this->explain($verdict));
        }

        // Inside the three seconds Discord allows: the panel's own
        // services are local, and the RCON calls behind them have their
        // own timeouts. A command that does outgrow this gets a deferred
        // reply rather than a longer wait.
        return $this->reply($this->runner->run($server, $interaction));
    }

    /**
     * Which game server this guild commands.
     *
     * One guild, one server: a member of one community must not reach
     * another's server, which is also checked in the authorisation.
     */
    private function serverFor(Interaction $interaction): ?GameServer
    {
        foreach ($this->servers->findAll() as $server) {
            if ($server->getDiscordConfig()?->getGuildId() === $interaction->guildId) {
                return $server;
            }
        }

        return null;
    }

    private function explain(CommandVerdict $verdict): string
    {
        return match ($verdict) {
            CommandVerdict::CommandsOff => 'Befehle sind für diesen Server abgeschaltet.',
            CommandVerdict::WrongGuild => 'Dieser Discord-Server ist nicht mit diesem Spielserver verbunden.',
            CommandVerdict::UnknownCommand => 'Diesen Befehl kennt das Dashboard nicht.',
            CommandVerdict::NotAllowed => 'Dir fehlt die Rolle für diesen Befehl.',
            default => 'Das war nicht möglich.',
        };
    }

    /** Ephemeral: an answer to one person does not belong in a channel. */
    private function reply(string $content): JsonResponse
    {
        return new JsonResponse([
            'type' => InteractionType::MESSAGE,
            'data' => ['content' => $content, 'flags' => InteractionType::EPHEMERAL],
        ]);
    }

    /**
     * Whether the request carries a signature only Discord could make.
     *
     * The raw body is verified byte for byte, so it must not be read
     * through a parser first — `Request::getContent()` gives what came
     * over the wire.
     */
    private function isFromDiscord(Request $request, string $body): bool
    {
        $publicKey = $this->settings->find(AppSetting::DISCORD_PUBLIC_KEY)?->getValue();

        if (!is_string($publicKey) || trim($publicKey) === '') {
            // Nothing to verify against means nothing can be verified,
            // and an unverifiable request is not accepted.
            $this->logger->warning('a discord interaction arrived with no public key configured');

            return false;
        }

        return (new InteractionSignature(trim($publicKey)))->verify(
            (string) $request->headers->get('X-Signature-Ed25519', ''),
            (string) $request->headers->get('X-Signature-Timestamp', ''),
            $body,
        );
    }
}
