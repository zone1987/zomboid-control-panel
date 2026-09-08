<?php

declare(strict_types=1);

namespace App\Panel;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Whether a newer panel release exists, asked of the GitHub releases API.
 *
 * Cached for hours rather than per request: GitHub allows 60 unauthenticated
 * calls an hour from one address, and a panel that asks on every page load
 * would spend them and start failing.
 */
final readonly class PanelUpdateChecker
{
    private const CACHE_KEY = 'panel.latest_release';
    /**
     * Long enough that many panel views share one GitHub call, short
     * enough that it never outlives the operator's chosen interval --
     * a cache above that would make a shorter setting re-read a stale
     * answer instead of asking.
     */
    private const CACHE_SECONDS = 300;
    private const TIMEOUT_SECONDS = 5;

    public function __construct(
        private HttpClientInterface $http,
        private CacheInterface $cache,
        private LoggerInterface $logger,
        private string $version,
        private string $repository,
    ) {
    }

    public function currentVersion(): string
    {
        return $this->version;
    }

    /**
     * @return array{
     *     current: string,
     *     latest: string|null,
     *     upToDate: bool|null,
     *     url: string|null
     * }
     */
    public function status(): array
    {
        $latest = $this->latestRelease();

        return [
            'current' => $this->version,
            'latest' => $latest['version'] ?? null,
            // Null rather than true when the check failed: not knowing is
            // not the same as being current.
            'upToDate' => $latest === null ? null : !self::isNewer($latest['version'], $this->version),
            'url' => $latest['url'] ?? null,
        ];
    }

    /** @return array{version: string, url: string}|null */
    private function latestRelease(): ?array
    {
        if ($this->repository === '') {
            return null;
        }

        /** @var array{version: string, url: string}|null */
        return $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): ?array {
            $item->expiresAfter(self::CACHE_SECONDS);

            try {
                $payload = $this->http->request(
                    'GET',
                    sprintf('https://api.github.com/repos/%s/releases/latest', $this->repository),
                    [
                        'timeout' => self::TIMEOUT_SECONDS,
                        'headers' => ['Accept' => 'application/vnd.github+json'],
                    ],
                )->toArray();
            } catch (\Throwable $exception) {
                // A rate limit, a private repository or no release yet all
                // land here, and none of them is worth an error in the
                // interface.
                $this->logger->info('Panel update check failed: {reason}', [
                    'reason' => $exception->getMessage(),
                ]);

                return null;
            }

            $tag = $payload['tag_name'] ?? null;

            if (!\is_string($tag) || trim($tag) === '') {
                return null;
            }

            $url = $payload['html_url'] ?? null;

            return [
                'version' => ltrim(trim($tag), 'vV'),
                'url' => \is_string($url) ? $url : '',
            ];
        });
    }

    /** Compares dotted numeric versions; anything unparseable counts as older. */
    public static function isNewer(string $candidate, string $against): bool
    {
        $left = self::parts($candidate);
        $right = self::parts($against);

        if ($left === [] || $right === []) {
            return false;
        }

        $length = max(\count($left), \count($right));

        for ($index = 0; $index < $length; ++$index) {
            $a = $left[$index] ?? 0;
            $b = $right[$index] ?? 0;

            if ($a !== $b) {
                return $a > $b;
            }
        }

        return false;
    }

    /** @return list<int> */
    private static function parts(string $version): array
    {
        if (preg_match('/^\d+(?:\.\d+)*/', trim($version), $matches) !== 1) {
            return [];
        }

        return array_map(intval(...), explode('.', $matches[0]));
    }
}
