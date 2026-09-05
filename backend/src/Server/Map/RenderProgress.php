<?php

declare(strict_types=1);

namespace App\Server\Map;

/**
 * What a world render is doing, as a file both sides can see.
 *
 * The render runs for hours in a worker; the interface polls. A file
 * rather than a table because it is written every few seconds and read
 * as often, and none of it is worth a migration.
 */
final readonly class RenderProgress
{
    public const IDLE = 'idle';
    public const RUNNING = 'running';
    public const DONE = 'done';
    public const FAILED = 'failed';
    public const STOPPED = 'stopped';

    public function __construct(private string $path)
    {
    }

    /** @return array<string, mixed> */
    public function read(): array
    {
        if (!is_file($this->path)) {
            return ['state' => self::IDLE];
        }

        try {
            $state = json_decode((string) file_get_contents($this->path), true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['state' => self::IDLE];
        }

        if (!\is_array($state)) {
            return ['state' => self::IDLE];
        }

        // A worker that died leaves "running" behind for ever, which
        // would keep the interface waiting on nothing.
        // A job that was queued and never picked up means no worker is
        // consuming; 90 seconds is long past any normal start.
        if (($state['phase'] ?? '') === 'queued'
            && time() - (int) ($state['updatedAt'] ?? 0) > 90) {
            $state['state'] = self::FAILED;
            $state['error'] = 'map.noWorker';

            return $state;
        }

        if (($state['state'] ?? '') === self::RUNNING
            && time() - (int) ($state['updatedAt'] ?? 0) > 300) {
            $state['state'] = self::FAILED;
            $state['error'] = 'map.renderStalled';
        }

        return $state;
    }

    /** @param array<string, mixed> $state */
    public function write(array $state): void
    {
        $state['stopRequested'] = $this->stopRequested();
        $state['paused'] = $this->isPaused();

        @mkdir(\dirname($this->path), 0o775, true);

        $state['updatedAt'] = time();

        // Written aside and moved, so a reader never catches half a file.
        $temporary = $this->path.'.'.getmypid();

        if (file_put_contents($temporary, json_encode($state, \JSON_THROW_ON_ERROR)) !== false) {
            rename($temporary, $this->path);
        }
    }

    public function clear(): void
    {
        @unlink($this->path);
    }

    /**
     * Asks the run to stop at the next batch boundary.
     *
     * A flag rather than killing the worker: a render interrupted
     * mid-upload leaves tiles whose arrival nobody confirmed, and the
     * batch boundary is where that question is already settled.
     */
    public function requestStop(): void
    {
        @mkdir(\dirname($this->stopPath()), 0o775, true);
        @touch($this->stopPath());
    }

    public function stopRequested(): bool
    {
        return is_file($this->stopPath());
    }

    public function clearStop(): void
    {
        @unlink($this->stopPath());
    }

    public function requestPause(): void
    {
        @mkdir(\dirname($this->pausePath()), 0o775, true);
        @touch($this->pausePath());
    }

    public function resume(): void
    {
        @unlink($this->pausePath());
    }

    public function isPaused(): bool
    {
        return is_file($this->pausePath());
    }

    private function pausePath(): string
    {
        return $this->path.'.pause';
    }

    private function stopPath(): string
    {
        return $this->path.'.stop';
    }

    public function isRunning(): bool
    {
        return ($this->read()['state'] ?? '') === self::RUNNING;
    }
}
