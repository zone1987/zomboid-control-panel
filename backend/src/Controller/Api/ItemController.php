<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\GameServer;
use App\Entity\ModerationAction;
use App\Entity\User;
use App\Repository\GameServerRepository;
use App\Security\Permission\Permission;
use App\Server\Players\ModerationRecorder;
use App\Server\Items\ItemCatalogue;
use App\Server\Items\ItemTranslations;
use App\Server\Translation\TranslationVerdict;
use App\Settings\SupportedLanguages;
use App\Server\Items\ItemGiver;
use App\Server\Rcon\RconException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/servers/{serverId}/items')]
#[IsGranted(Permission::GiveItems->value)]
final class ItemController extends AbstractController
{
    public function __construct(
        private readonly GameServerRepository $servers,
        private readonly ItemCatalogue $catalogue,
        private readonly ItemTranslations $translations,
        private readonly SupportedLanguages $languages,
        private readonly ItemGiver $giver,
        private readonly EntityManagerInterface $entityManager,
        private readonly ModerationRecorder $recorder,
    ) {
    }

    /** Everything the server knows, base game and mods alike. */
    #[Route('', name: 'api_items_list', methods: ['GET'])]
    public function list(string $serverId, Request $request): JsonResponse
    {
        $server = $this->servers->find($serverId);

        if (!$server instanceof GameServer) {
            return $this->notFound();
        }

        $refresh = $request->query->getBoolean('refresh');
        $catalogue = $this->catalogue->forServer($server, $refresh);

        // The bridge reports names in the server's own language, which is
        // usually English. The game ships a file per language, so the
        // panel's language wins where one exists.
        $language = $request->query->getString('language', $request->getLocale());

        // Only a language the interface itself speaks: anything else is
        // a path the caller made up.
        $verdict = $this->languages->supports($language)
            ? $this->translations->verdictFor($server, $language, $refresh)
            : TranslationVerdict::unsupportedLanguage($language);

        $names = $verdict->names;

        if ($names !== []) {
            $catalogue['items'] = array_map(
                static fn (array $item): array => isset($names[$item['type']])
                    ? [...$item, 'name' => $names[$item['type']]]
                    : $item,
                $catalogue['items'],
            );
            $catalogue['language'] = $verdict->language;
        }

        // Present whichever way it went: the empty case is the one worth explaining.
        $catalogue['translation'] = $verdict->toArray();

        $response = new JsonResponse([
            ...$catalogue,
            'limits' => [
                'perCommand' => ItemGiver::PER_COMMAND,
                'maxTotal' => ItemGiver::MAX_TOTAL,
            ],
        ]);

        // Five thousand items are the same five thousand until the server
        // restarts, so a browser that already has them should be told to
        // keep them rather than sent them again.
        $response->setEtag(sprintf(
            '%s-%d-%s',
            $catalogue['generatedAt'] ?? 0,
            $catalogue['fileSize'] ?? 0,
            $verdict->state,
        ));
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'private, max-age=60, must-revalidate');
        $response->isNotModified($request);

        return $response;
    }

    #[Route('/give', name: 'api_items_give', methods: ['POST'])]
    public function give(string $serverId, Request $request, #[CurrentUser] User $actor): JsonResponse
    {
        $server = $this->servers->find($serverId);

        if (!$server instanceof GameServer) {
            return $this->notFound();
        }

        $payload = $this->payloadOf($request);
        $username = $payload['username'] ?? null;
        $items = $this->itemsFrom($payload['items'] ?? null);

        if (!\is_string($username) || trim($username) === '') {
            return $this->invalid(['username' => 'validation.required']);
        }

        if ($items === []) {
            return $this->invalid(['items' => 'validation.required']);
        }

        try {
            $results = $this->giver->give($server, $username, $items);
        } catch (RconException $exception) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => $exception->messageKey(),
                'detail' => $exception->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }

        $this->record($server, $username, $actor, $results);

        return new JsonResponse([
            'status' => 'sent',
            'username' => $username,
            'results' => $results,
        ]);
    }

    /**
     * @param list<array{type: string, count: int, reply: string, failed: bool}> $results
     */
    private function record(GameServer $server, string $username, User $actor, array $results): void
    {
        $delivered = array_filter($results, static fn (array $r): bool => !$r['failed']);

        if ($delivered === []) {
            return;
        }

        $summary = implode(', ', array_map(
            static fn (array $r): string => sprintf('%dx %s', $r['count'], $r['type']),
            $delivered,
        ));

        $this->recorder->record(
            $server,
            ModerationAction::ITEMS,
            $username,
            $actor,
            mb_substr($summary, 0, 500),
        );
    }

    /**
     * @return list<array{type: string, count: int}>
     */
    private function itemsFrom(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $items = [];

        foreach ($raw as $entry) {
            if (!\is_array($entry) || !\is_string($entry['type'] ?? null)) {
                continue;
            }

            $items[] = [
                'type' => $entry['type'],
                'count' => max(1, (int) ($entry['count'] ?? 1)),
            ];
        }

        // One request should not turn into hundreds of RCON round trips.
        return \array_slice($items, 0, 50);
    }

    /** @param array<string, string> $errors */
    private function invalid(array $errors): JsonResponse
    {
        return new JsonResponse(
            ['status' => 'failed', 'errors' => $errors],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    /** @return array<string, mixed> */
    private function payloadOf(Request $request): array
    {
        try {
            return $request->toArray();
        } catch (\Throwable) {
            return [];
        }
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(['status' => 'failed', 'error' => 'errors.notFound'], Response::HTTP_NOT_FOUND);
    }
}
