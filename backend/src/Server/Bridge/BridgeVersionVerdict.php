<?php

declare(strict_types=1);

namespace App\Server\Bridge;

/**
 * Which bridge is actually in charge, out of the three there can be.
 *
 * The file on disk, the mod in the running game and the one the panel
 * ships are three separate things. The mod loads at server start, so an
 * upload changes the file and nothing else — and a status that reads the
 * file alone reports "up to date" while the game answers commands the
 * older handler set does not have.
 */
final readonly class BridgeVersionVerdict
{
    public const UP = 'up';
    public const STALE = 'stale';

    private function __construct(
        public string $state,
        public ?string $detail,
        public ?string $version,
    ) {
    }

    /**
     * @param string|null $running   what the bridge writes into its own output
     * @param string|null $onDisk    what the file on the server declares
     * @param string      $available what the panel ships
     */
    public static function of(?string $running, ?string $onDisk, string $available): self
    {
        // Uploaded but not loaded: the operator has done half the job and
        // is the only one who can do the other half.
        if ($running !== null && $onDisk !== null && $running !== $onDisk) {
            return new self(self::STALE, 'bridge.restartNeeded', $running);
        }

        $current = $running ?? $onDisk;

        if ($current === null) {
            return new self(self::STALE, 'bridge.noReading', null);
        }

        return $current === $available
            ? new self(self::UP, null, $current)
            : new self(self::STALE, 'bridge.outdated', $current);
    }
}
