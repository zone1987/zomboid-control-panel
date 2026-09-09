<?php

declare(strict_types=1);

namespace App\Server\Mods;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads the Steam Workshop.
 *
 * Three endpoints with different demands, established by probing them:
 * details are public, while searching and dependencies need a Web API
 * key. Without one the panel still works from an id, so `NoKey` is a
 * state the interface explains rather than an error it reports.
 */
final class WorkshopClient implements WorkshopSource
{
    private const APP_ID = 108600;

    private const DETAILS = 'https://api.steampowered.com/ISteamRemoteStorage/GetPublishedFileDetails/v1/';
    private const QUERY = 'https://api.steampowered.com/IPublishedFileService/QueryFiles/v1/';
    private const RICH_DETAILS = 'https://api.steampowered.com/IPublishedFileService/GetDetails/v1/';

    private const TIMEOUT_SECONDS = 8;

    /** Steam refuses more, and asking for more silently truncates. */
    private const MAX_IDS_PER_CALL = 100;

    public function __construct(
        private HttpClientInterface $http,
        private SteamCredentials $credentials,
        private LoggerInterface $logger,
    ) {
    }

    public function hasKey(): bool
    {
        return $this->apiKey() !== null;
    }

    /**
     * Looks up items by id. Works without a key, which is what keeps the
     * panel usable for an operator who has not entered one.
     *
     * @param list<string> $workshopIds
     *
     * @return WorkshopResult<WorkshopItem>
     */
    public function itemsById(array $workshopIds): WorkshopResult
    {
        $ids = array_values(array_unique(array_filter($workshopIds, self::isId(...))));

        if ($ids === []) {
            return WorkshopResult::ok([]);
        }

        $items = [];

        foreach (array_chunk($ids, self::MAX_IDS_PER_CALL) as $chunk) {
            $body = ['itemcount' => \count($chunk)];

            foreach ($chunk as $index => $id) {
                $body['publishedfileids['.$index.']'] = $id;
            }

            try {
                $response = $this->http->request('POST', self::DETAILS, [
                    'body' => $body,
                    'timeout' => self::TIMEOUT_SECONDS,
                ]);

                $payload = $response->toArray(false);
            } catch (\Throwable $exception) {
                $this->logger->info('Workshop details lookup failed.', ['exception' => $exception]);

                return WorkshopResult::failed(WorkshopState::Unreachable);
            }

            foreach ($payload['response']['publishedfiledetails'] ?? [] as $raw) {
                // result 1 is success; anything else means Steam has no
                // such item, which is not the same as the call failing.
                if (($raw['result'] ?? 0) !== 1) {
                    continue;
                }

                $items[] = $this->toItem($raw);
            }
        }

        return WorkshopResult::ok($items);
    }

    /**
     * Searches the workshop. Needs a key: without one Steam answers 403.
     *
     * @return WorkshopResult<WorkshopItem>
     */
    public function search(
        string $term = '',
        array $tags = [],
        string $sort = 'trend',
        int $page = 1,
        int $perPage = 30,
    ): WorkshopResult {
        $key = $this->apiKey();

        if ($key === null) {
            return WorkshopResult::failed(WorkshopState::NoKey);
        }

        $query = [
            'key' => $key,
            'appid' => self::APP_ID,
            'query_type' => self::queryType($sort),
            'page' => max(1, $page),
            'numperpage' => min(100, max(1, $perPage)),
            'search_text' => $term,
            'return_tags' => true,
            'return_previews' => true,
            'return_details' => true,
            'return_short_description' => true,
            // Ready-to-use items only: collections and guides would
            // otherwise appear beside the mods and cannot be installed
            // the same way.
            'filetype' => 0,
        ];

        foreach (array_values($tags) as $index => $tag) {
            $query['requiredtags['.$index.']'] = $tag;
        }

        $payload = $this->get(self::QUERY, $query);

        if ($payload instanceof WorkshopState) {
            return WorkshopResult::failed($payload);
        }

        $items = [];

        foreach ($payload['response']['publishedfiledetails'] ?? [] as $raw) {
            if (($raw['result'] ?? 1) !== 1) {
                continue;
            }

            $items[] = $this->toItem($raw);
        }

        $total = $payload['response']['total'] ?? \count($items);

        return WorkshopResult::ok($items, \is_int($total) ? $total : \count($items));
    }

    /**
     * One item with what only the authenticated endpoint carries, above
     * all its declared dependencies.
     *
     * @return WorkshopResult<WorkshopItem>
     */
    public function details(string $workshopId): WorkshopResult
    {
        if (!self::isId($workshopId)) {
            return WorkshopResult::failed(WorkshopState::NotFound);
        }

        $key = $this->apiKey();

        if ($key === null) {
            // Falls back to the public endpoint, which carries
            // everything except dependencies -- better than nothing,
            // and the caller can tell from the empty list plus
            // `hasKey()` why they are missing.
            return $this->itemsById([$workshopId]);
        }

        $payload = $this->get(self::RICH_DETAILS, [
            'key' => $key,
            'publishedfileids[0]' => $workshopId,
            'includetags' => true,
            'includechildren' => true,
            'includevotes' => true,
            'includeadditionalpreviews' => true,
            'short_description' => false,
        ]);

        if ($payload instanceof WorkshopState) {
            return WorkshopResult::failed($payload);
        }

        $raw = $payload['response']['publishedfiledetails'][0] ?? null;

        if (!\is_array($raw) || ($raw['result'] ?? 0) !== 1) {
            return WorkshopResult::failed(WorkshopState::NotFound);
        }

        return WorkshopResult::ok([$this->toItem($raw)]);
    }

    /**
     * @param array<string, scalar> $query
     *
     * @return array<mixed>|WorkshopState the state when the call failed
     */
    private function get(string $endpoint, array $query): array|WorkshopState
    {
        try {
            $response = $this->http->request('GET', $endpoint, [
                'query' => $query,
                'timeout' => self::TIMEOUT_SECONDS,
            ]);

            $status = $response->getStatusCode();

            // Steam does not document its rate limit, so a 429 is
            // treated as its own recoverable state rather than folded
            // into "unreachable" -- the advice differs.
            if ($status === 429) {
                return WorkshopState::RateLimited;
            }

            if ($status === 401 || $status === 403) {
                return WorkshopState::NoKey;
            }

            if ($status >= 400) {
                return WorkshopState::Unreachable;
            }

            return $response->toArray(false);
        } catch (\Throwable $exception) {
            $this->logger->info('Workshop request failed.', [
                'endpoint' => $endpoint,
                'exception' => $exception,
            ]);

            return WorkshopState::Unreachable;
        }
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function toItem(array $raw): WorkshopItem
    {
        $tags = [];

        foreach ($raw['tags'] ?? [] as $tag) {
            $name = \is_array($tag) ? ($tag['tag'] ?? null) : $tag;

            if (\is_string($name) && $name !== '') {
                $tags[] = $name;
            }
        }

        $children = [];

        foreach ($raw['children'] ?? [] as $child) {
            $childId = $child['publishedfileid'] ?? null;

            if (\is_string($childId) || \is_int($childId)) {
                $children[] = (string) $childId;
            }
        }

        // A collection's children are its contents, a mod's are what it
        // requires -- the same field carrying two meanings, so the item
        // type has to decide how the caller reads them.
        $isCollection = ((int) ($raw['file_type'] ?? 0)) === 2;

        return new WorkshopItem(
            workshopId: (string) ($raw['publishedfileid'] ?? ''),
            title: (string) ($raw['title'] ?? ''),
            description: (string) ($raw['description'] ?? $raw['short_description'] ?? ''),
            previewUrl: self::stringOrNull($raw['preview_url'] ?? null),
            tags: $tags,
            fileSize: (int) ($raw['file_size'] ?? 0),
            createdAt: self::timestamp($raw['time_created'] ?? null),
            updatedAt: self::timestamp($raw['time_updated'] ?? null),
            subscriptions: (int) ($raw['lifetime_subscriptions'] ?? $raw['subscriptions'] ?? 0),
            favourites: (int) ($raw['lifetime_favorited'] ?? $raw['favorited'] ?? 0),
            views: (int) ($raw['views'] ?? 0),
            dependencies: $children,
            isCollection: $isCollection,
            creatorSteamId: self::stringOrNull($raw['creator'] ?? null),
        );
    }

    private function apiKey(): ?string
    {
        return $this->credentials->steamApiKey();
    }

    private static function queryType(string $sort): int
    {
        // Steam's EPublishedFileQueryType; only the four the interface
        // offers, mirroring the workshop's own tabs.
        return match ($sort) {
            'subscriptions' => 21,
            'updated' => 21,
            'recent' => 1,
            default => 3,
        };
    }

    private static function isId(mixed $value): bool
    {
        return \is_string($value) && preg_match('/^\d{1,20}$/', $value) === 1;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }

    private static function timestamp(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_int($value) || $value <= 0) {
            return null;
        }

        return (new \DateTimeImmutable())->setTimestamp($value);
    }
}
