<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Map;

use App\Server\Map\RenderProgress;
use PHPUnit\Framework\TestCase;

final class RenderProgressTest extends TestCase
{
    private string $path;
    private RenderProgress $progress;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/render-'.bin2hex(random_bytes(6)).'.json';
        $this->progress = new RenderProgress($this->path);
    }

    protected function tearDown(): void
    {
        foreach ([$this->path, $this->path.'.stop', $this->path.'.pause'] as $file) {
            @unlink($file);
        }
    }

    /**
     * The handler writes its counters every few seconds. A stop kept in
     * the same file was overwritten by the next write, so the button
     * never took effect.
     */
    public function testAStopSurvivesTheHandlerWritingItsCounters(): void
    {
        $this->progress->write(['state' => RenderProgress::RUNNING, 'cellsDone' => 10]);
        $this->progress->requestStop();
        $this->progress->write(['state' => RenderProgress::RUNNING, 'cellsDone' => 16]);

        self::assertTrue($this->progress->stopRequested());
        self::assertTrue($this->progress->read()['stopRequested']);
    }

    public function testAPauseSurvivesTheSameWay(): void
    {
        $this->progress->write(['state' => RenderProgress::RUNNING]);
        $this->progress->requestPause();
        $this->progress->write(['state' => RenderProgress::RUNNING, 'cellsDone' => 30]);

        self::assertTrue($this->progress->isPaused());

        $this->progress->resume();

        self::assertFalse($this->progress->isPaused());
    }

    public function testClearingAStopLetsTheNextRunStart(): void
    {
        $this->progress->requestStop();
        $this->progress->clearStop();

        self::assertFalse($this->progress->stopRequested());
    }

    /** A worker that died must not leave the interface waiting for ever. */
    public function testAStaleRunningStateIsReportedAsFailed(): void
    {
        file_put_contents($this->path, json_encode([
            'state' => RenderProgress::RUNNING,
            'updatedAt' => time() - 600,
        ]));

        self::assertSame(RenderProgress::FAILED, $this->progress->read()['state']);
    }
}
