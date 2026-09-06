<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\GameServer;
use App\Entity\ModerationAction;
use App\Entity\PlayerNote;
use App\Entity\User;
use App\Repository\GameServerRepository;
use App\Repository\ModerationActionRepository;
use App\Repository\PlayerNoteRepository;
use App\Security\Permission\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The staff's own memory of a player, and what was done to them.
 *
 * Two reads and one write, kept together because the dossier's last tab
 * shows both: the note is what the operators know, the log is what they
 * did, and neither makes sense alone.
 *
 * Writing a note needs `KickPlayers` rather than a permission of its
 * own: somebody trusted to remove a player is trusted to write down why,
 * and inventing a tenth permission for a text field would be governance
 * nobody asked for.
 */
#[Route('/api/servers/{serverId}/players/{username}')]
#[IsGranted(Permission::ViewPlayers->value)]
final class PlayerNoteController extends AbstractController
{
    public function __construct(
        private readonly GameServerRepository $servers,
        private readonly PlayerNoteRepository $notes,
        private readonly ModerationActionRepository $actions,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/note', name: 'api_players_note_read', methods: ['GET'])]
    public function read(string $serverId, string $username): JsonResponse
    {
        $server = $this->servers->find($serverId);

        if (!$server instanceof GameServer) {
            return $this->notFound();
        }

        $note = $this->notes->findFor($server, $username);

        return new JsonResponse([
            'note' => $note?->getNote(),
            'tags' => $note?->getTags() ?? [],
            'updatedBy' => $note?->getUpdatedBy()?->getDisplayName(),
            'updatedAt' => $note?->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            // Both sets, so the interface can offer what this server
            // already uses beside the fixed suggestions.
            'suggested' => PlayerNote::SUGGESTED,
            'inUse' => $this->notes->tagsInUse($server),
            'limits' => [
                'note' => PlayerNote::MAX_LENGTH,
                'tags' => PlayerNote::MAX_TAGS,
                'tagLength' => PlayerNote::MAX_TAG_LENGTH,
            ],
        ]);
    }

    #[Route('/note', name: 'api_players_note_write', methods: ['PUT'])]
    #[IsGranted(Permission::KickPlayers->value)]
    public function write(
        string $serverId,
        string $username,
        Request $request,
        #[CurrentUser] User $actor,
    ): JsonResponse {
        $server = $this->servers->find($serverId);

        if (!$server instanceof GameServer) {
            return $this->notFound();
        }

        $payload = $this->payloadOf($request);
        $text = $payload['note'] ?? null;
        $tags = $payload['tags'] ?? [];

        if ($text !== null && !\is_string($text)) {
            return $this->invalid('note');
        }

        if (\is_string($text) && mb_strlen($text) > PlayerNote::MAX_LENGTH) {
            return $this->invalid('note');
        }

        if (!\is_array($tags)) {
            return $this->invalid('tags');
        }

        $note = $this->notes->findFor($server, $username) ?? new PlayerNote($server, $username);

        $note->revise(
            \is_string($text) ? $text : null,
            array_values(array_filter($tags, \is_string(...))),
            $actor,
        );

        // An emptied note leaves no row behind: a dossier full of blank
        // notes is a dossier nobody trusts, and the absence is the state.
        if ($note->isEmpty()) {
            if ($this->entityManager->contains($note)) {
                $this->entityManager->remove($note);
                $this->entityManager->flush();
            }

            return new JsonResponse(['status' => 'saved', 'note' => null, 'tags' => []]);
        }

        $this->entityManager->persist($note);
        $this->entityManager->flush();

        return new JsonResponse([
            'status' => 'saved',
            'note' => $note->getNote(),
            'tags' => $note->getTags(),
            'updatedBy' => $note->getUpdatedBy()?->getDisplayName(),
            'updatedAt' => $note->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ]);
    }

    /**
     * What was done to this player, and only to them.
     *
     * The reference panel shows everybody's activity inside a view
     * already scoped to one player, and then needs a second search box
     * to make that usable. This is the same data asked the right
     * question.
     */
    #[Route('/history', name: 'api_players_own_history', methods: ['GET'])]
    public function history(string $serverId, string $username): JsonResponse
    {
        $server = $this->servers->find($serverId);

        if (!$server instanceof GameServer) {
            return $this->notFound();
        }

        return new JsonResponse([
            'items' => array_map(
                static fn (ModerationAction $entry): array => [
                    'action' => $entry->getAction(),
                    'reason' => $entry->getReason(),
                    'reply' => $entry->getReply(),
                    'performedBy' => $entry->getPerformedBy()?->getDisplayName(),
                    'performedAt' => $entry->getPerformedAt()->format(\DateTimeInterface::ATOM),
                ],
                $this->actions->forPlayer($server, $username),
            ),
        ]);
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

    private function invalid(string $field): JsonResponse
    {
        return new JsonResponse(
            ['status' => 'failed', 'errors' => [$field => 'validation.invalid']],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(
            ['status' => 'failed', 'error' => 'errors.notFound'],
            Response::HTTP_NOT_FOUND,
        );
    }
}
