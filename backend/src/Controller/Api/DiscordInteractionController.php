<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\AppSetting;
use App\Repository\AppSettingRepository;
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

        $this->logger->info('discord interaction received', [
            'type' => $type,
            'command' => $payload['data']['name'] ?? null,
        ]);

        // The command dispatcher lands in the next step; until then an
        // honest refusal beats a silent nothing, and it is ephemeral so
        // it does not clutter a channel.
        return new JsonResponse([
            'type' => InteractionType::MESSAGE,
            'data' => [
                'content' => 'Dieser Befehl ist noch nicht eingerichtet.',
                'flags' => InteractionType::EPHEMERAL,
            ],
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
