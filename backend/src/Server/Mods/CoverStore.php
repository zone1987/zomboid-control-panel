<?php

declare(strict_types=1);

namespace App\Server\Mods;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches a mod's cover once, shrinks it, and serves it from here.
 *
 * Steam offers no resized variant -- the resize parameters its store
 * pages use answer with an error page on this host -- so a grid of
 * forty tiles would pull the originals, measured at up to 738 KB each
 * and sometimes animated GIFs.
 *
 * Serving them ourselves also keeps `img-src 'self'` intact and keeps
 * every viewer's address away from Valve, which a decorative thumbnail
 * does not justify exposing.
 *
 * **A cache with a ceiling.** A cover shrinks to about 13 KB (measured:
 * a 738 KB, 512x512 source), so a real server's mod list costs a
 * megabyte or two and every build 42 mod in the workshop would cost
 * ~314 MB. The ceiling sits just past that, because browsing has no
 * natural end and a disk filling silently on a hosted panel is the one
 * outcome nobody would notice. Past it the least recently used covers
 * go, which is safe precisely because each can be fetched again.
 */
final readonly class CoverStore
{
    /** Twice the tile's CSS width, so it stays sharp on a dense screen. */
    private const WIDTH = 320;

    /** A cover Steam cannot be persuaded to shrink is still not this big. */
    private const MAX_BYTES = 8_388_608;

    /**
     * What the whole store may hold.
     *
     * At ~13 KB a cover this is about 24,000 of them -- every build 42
     * mod the workshop has, which is the worst case the user accepted
     * explicitly. The cap exists because browsing has no natural end,
     * not because the expected size is a problem: a real server's mod
     * list costs a megabyte or two.
     */
    private const MAX_STORE_BYTES = 335_544_320;

    /** Sweeping on every write would stat the directory constantly. */
    private const SWEEP_EVERY = 50;

    private const TIMEOUT_SECONDS = 10;

    public function __construct(
        private string $directory,
        private HttpClientInterface $http,
        private LoggerInterface $logger,
    ) {
    }

    public function has(string $workshopId): bool
    {
        $path = $this->pathFor($workshopId);

        return $path !== null && is_file($path);
    }

    public function pathFor(string $workshopId): ?string
    {
        // The id comes from a URL, so it is checked rather than trusted:
        // anything but digits could walk out of the directory.
        if (preg_match('/^\d{1,20}$/', $workshopId) !== 1) {
            return null;
        }

        return $this->directory.'/'.$workshopId.'.webp';
    }

    /**
     * Stores the cover for this mod, if it has one and it can be read.
     *
     * Returns whether a file is now there — false covers "no preview",
     * "could not fetch" and "could not decode" alike, because the caller
     * does the same thing in all three: show the placeholder.
     */
    public function fetch(WorkshopItem $item): bool
    {
        $path = $this->pathFor($item->workshopId);

        if ($path === null || $item->previewUrl === null) {
            return false;
        }

        if (is_file($path)) {
            return true;
        }

        try {
            $response = $this->http->request('GET', $item->previewUrl, [
                'timeout' => self::TIMEOUT_SECONDS,
            ]);

            if ($response->getStatusCode() !== 200) {
                return false;
            }

            $bytes = $response->getContent(false);
        } catch (\Throwable $exception) {
            $this->logger->info('Workshop cover fetch failed.', [
                'workshopId' => $item->workshopId,
                'exception' => $exception,
            ]);

            return false;
        }

        if ($bytes === '' || \strlen($bytes) > self::MAX_BYTES) {
            return false;
        }

        return $this->write($path, $bytes);
    }

    private function write(string $path, string $bytes): bool
    {
        // A gd built without webp has no imagewebp() at all, and every
        // cover then failed to save without a word — the mods page just
        // showed placeholders. Said once, loudly, rather than never.
        if (!\function_exists('imagewebp')) {
            $this->logger->error(
                'This PHP has gd without webp support, so mod covers cannot be stored. '
                .'Rebuild the image with --with-webp.',
            );

            return false;
        }

        $source = @imagecreatefromstring($bytes);

        if ($source === false) {
            return false;
        }

        try {
            $width = imagesx($source);
            $height = imagesy($source);

            if ($width < 1 || $height < 1) {
                return false;
            }

            // Only ever downwards: enlarging a small cover would cost
            // bytes and gain nothing.
            $targetWidth = min(self::WIDTH, $width);
            $targetHeight = (int) round($height * ($targetWidth / $width));

            $out = imagecreatetruecolor($targetWidth, max(1, $targetHeight));
            imagealphablending($out, false);
            imagesavealpha($out, true);
            imagecopyresampled($out, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

            if (!is_dir($this->directory)) {
                mkdir($this->directory, 0o775, true);
            }

            $written = imagewebp($out, $path, 80);
            imagedestroy($out);

            if ($written) {
                $this->sweepOccasionally();
            }

            return $written;
        } finally {
            imagedestroy($source);
        }
    }

    /**
     * Drops the least recently used covers once the store outgrows its
     * ceiling — but only now and then, since counting the directory on
     * every single write would cost more than it saves.
     */
    private function sweepOccasionally(): void
    {
        if (random_int(1, self::SWEEP_EVERY) !== 1) {
            return;
        }

        $files = glob($this->directory.'/*.webp');

        if ($files === false || $files === []) {
            return;
        }

        $total = 0;
        $entries = [];

        foreach ($files as $file) {
            $size = @filesize($file);

            if ($size === false) {
                continue;
            }

            $total += $size;
            // Read time, not write time: a cover shown every day should
            // outlive one fetched once while paging past it.
            $entries[] = ['path' => $file, 'size' => $size, 'used' => @fileatime($file) ?: 0];
        }

        if ($total <= self::MAX_STORE_BYTES) {
            return;
        }

        usort($entries, static fn (array $a, array $b): int => $a['used'] <=> $b['used']);

        // Down to four fifths rather than exactly to the line, so the
        // next few writes do not each trigger another sweep.
        $target = (int) (self::MAX_STORE_BYTES * 0.8);

        foreach ($entries as $entry) {
            if ($total <= $target) {
                break;
            }

            if (@unlink($entry['path'])) {
                $total -= $entry['size'];
            }
        }

        $this->logger->info('Trimmed the workshop cover store.', ['bytesAfter' => $total]);
    }
}
