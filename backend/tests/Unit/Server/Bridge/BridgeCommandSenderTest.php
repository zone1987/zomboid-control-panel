<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Bridge;

use App\Entity\FtpConfig;
use App\Entity\GameServer;
use App\Server\Bridge\BridgeCommand;
use App\Server\Bridge\BridgeCommandFailed;
use App\Server\Bridge\BridgeCommandSender;
use App\Server\Bridge\BridgeFiles;
use App\Server\Bridge\BridgeResult;
use App\Server\Bridge\InvalidBridgeCommand;
use App\Server\Storage\FileBrowserInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class BridgeCommandSenderTest extends TestCase
{
    public function testWritesTheCommandAndReadsTheAnswer(): void
    {
        $files = new FakeFiles();
        $files->answer(1, ['ok' => true, 'message' => 'pong', 'seq' => 1]);

        $result = $this->sender($files)->send($this->server(), BridgeCommand::Ping);

        self::assertTrue($result->ok);
        self::assertSame('pong', $result->message);
        self::assertArrayHasKey(BridgeFiles::commandFile(1), $files->written);
    }

    /**
     * The cursor is written after the command it accounts for, so the
     * number in it is always backed by a file that is really there.
     */
    public function testWritesItsCursorAfterTheCommand(): void
    {
        $files = new FakeFiles();
        $files->answer(1, ['ok' => true, 'message' => '']);

        $this->sender($files)->send($this->server(), BridgeCommand::Ping);

        self::assertSame(
            [BridgeFiles::commandFile(1), BridgeFiles::PANEL_CURSOR],
            array_keys($files->written),
        );
        self::assertSame(
            ['nextCommandSeq' => 2],
            json_decode($files->written[BridgeFiles::PANEL_CURSOR], true),
        );
    }

    public function testNumbersEachCommandInTurn(): void
    {
        $files = new FakeFiles();
        $files->answer(1, ['ok' => true, 'message' => '']);
        $files->answer(2, ['ok' => true, 'message' => '']);

        $sender = $this->sender($files);
        $server = $this->server();

        $sender->send($server, BridgeCommand::Ping);
        $sender->send($server, BridgeCommand::Ping);

        self::assertArrayHasKey(BridgeFiles::commandFile(2), $files->written);
    }

    /**
     * Starting at 1 again after a restart would make the bridge ignore
     * everything until it caught up to where it already was.
     */
    public function testPicksUpFromTheBridgesOwnCursorWhenItHasNoMemory(): void
    {
        $files = new FakeFiles();
        $files->contents[BridgeFiles::BRIDGE_CURSOR] = '{"lastCommandSeq":41}';
        $files->answer(42, ['ok' => true, 'message' => '']);

        $this->sender($files)->send($this->server(), BridgeCommand::Ping);

        self::assertArrayHasKey(BridgeFiles::commandFile(42), $files->written);
    }

    public function testStartsAtOneWhenTheBridgeHasNoCursorEither(): void
    {
        $files = new FakeFiles();
        $files->answer(1, ['ok' => true, 'message' => '']);

        $this->sender($files)->send($this->server(), BridgeCommand::Ping);

        self::assertArrayHasKey(BridgeFiles::commandFile(1), $files->written);
    }

    public function testReportsAFailureTheBridgeDescribed(): void
    {
        $files = new FakeFiles();
        $files->answer(1, ['ok' => false, 'message' => 'hour must be between 0 and 24']);

        $result = $this->sender($files)->send($this->server(), BridgeCommand::SetTime, ['hour' => 12]);

        self::assertFalse($result->ok);
        self::assertSame('hour must be between 0 and 24', $result->message);
    }

    public function testGivesUpWhenTheBridgeNeverAnswers(): void
    {
        $this->expectException(BridgeCommandFailed::class);

        $this->sender(new FakeFiles())->send($this->server(), BridgeCommand::Ping);
    }

    public function testRefusesToSendWithoutFileAccess(): void
    {
        $this->expectException(BridgeCommandFailed::class);

        $this->sender(new FakeFiles())->send(new GameServer('No FTP'), BridgeCommand::Ping);
    }

    public function testChecksTheArgumentsBeforeWritingAnything(): void
    {
        $files = new FakeFiles();

        try {
            $this->sender($files)->send($this->server(), BridgeCommand::SetTime, ['hour' => 99]);
            self::fail('An hour of 99 should have been refused.');
        } catch (InvalidBridgeCommand) {
            self::assertSame([], $files->written);
        }
    }

    public function testTakesAClimateValueByNameOrByIndex(): void
    {
        self::assertSame(
            ['index' => 5, 'value' => 0.8],
            BridgeCommand::SetClimateValue->validate(['name' => 'fog', 'value' => 0.8]),
        );

        self::assertSame(
            ['index' => 5, 'value' => 0.8],
            BridgeCommand::SetClimateValue->validate(['index' => 5, 'value' => 0.8]),
        );
    }

    public function testRefusesAClimateValueThatDoesNotExist(): void
    {
        $this->expectException(InvalidBridgeCommand::class);

        BridgeCommand::SetClimateValue->validate(['name' => 'gravity', 'value' => 1]);
    }

    /** A sound goes either at a point or on a player, never neither. */
    public function testASoundTakesCoordinatesOrAPlayer(): void
    {
        $atPoint = BridgeCommand::PlaySound->validate([
            'x' => 100, 'y' => 200, 'radius' => 50, 'volume' => 50,
        ]);
        self::assertSame(100, $atPoint['x']);
        self::assertArrayNotHasKey('player', $atPoint);

        $atPlayer = BridgeCommand::PlaySound->validate([
            'player' => 'bob', 'radius' => 50, 'volume' => 50,
        ]);
        self::assertSame('bob', $atPlayer['player']);
        self::assertArrayNotHasKey('x', $atPlayer);
    }

    public function testRefusesASoundWithNeither(): void
    {
        $this->expectException(InvalidBridgeCommand::class);

        BridgeCommand::PlaySound->validate(['radius' => 50, 'volume' => 50]);
    }

    public function testReadsAnAnswerThatIsNotJson(): void
    {
        $this->expectException(BridgeCommandFailed::class);

        BridgeResult::parse('not json at all');
    }

    public function testTreatsAMissingOkAsAFailure(): void
    {
        self::assertFalse(BridgeResult::parse('{"message":"something"}')->ok);
    }

    private function sender(FakeFiles $files): BridgeCommandSender
    {
        // Short waits: the point under test is the shape of the queue,
        // not how patient it is.
        return new BridgeCommandSender($files, new ArrayAdapter(), timeoutSeconds: 0.2, pollMicroseconds: 20_000);
    }

    private function server(): GameServer
    {
        $server = new GameServer('Test');
        new FtpConfig($server, 'ftp', 'example.invalid', 21, 'user', 'secret');

        return $server;
    }
}

final class FakeFiles implements FileBrowserInterface
{
    /** @var array<string, string> */
    public array $written = [];

    /** @var array<string, string> */
    public array $contents = [];

    /** @param array<string, mixed> $payload */
    public function answer(int $sequence, array $payload): void
    {
        $this->contents[BridgeFiles::resultFile($sequence)] = json_encode($payload, \JSON_THROW_ON_ERROR);
    }

    public function upload(\App\Entity\FtpConfig $config, string $path, string $contents): void
    {
        $this->written[$path] = $contents;
    }

    public function fileExists(\App\Entity\FtpConfig $config, string $path): bool
    {
        return isset($this->contents[$path]);
    }

    public function readTail(\App\Entity\FtpConfig $config, string $path, int $maxBytes = 65536): string
    {
        return $this->contents[$path] ?? '';
    }

    public function listDirectory(\App\Entity\FtpConfig $config, string $path = ''): array
    {
        return [];
    }

    public function verify(\App\Entity\FtpConfig $config): array
    {
        return [];
    }

    public function directoryExists(\App\Entity\FtpConfig $config, string $path): bool
    {
        return true;
    }
}
