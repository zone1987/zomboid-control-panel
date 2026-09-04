<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\GameServer;
use App\Entity\RconConfig;
use App\Server\Rcon\RconAuthenticationFailed;
use App\Server\Rcon\RconClientInterface;
use App\Server\Rcon\RconUnreachable;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

/**
 * Runs against a local Source RCON server that answers the way Project
 * Zomboid does, so the protocol handling is exercised rather than mocked.
 */
final class RconClientTest extends KernelTestCase
{
    private const PORT = 27055;
    private const PASSWORD = 'rcon-test-password';

    private static ?Process $server = null;

    private RconClientInterface $client;

    public static function setUpBeforeClass(): void
    {
        self::$server = new Process([
            'php',
            __DIR__.'/../Support/fake-rcon-server.php',
            self::PASSWORD,
            (string) self::PORT,
        ]);

        self::$server->start();

        // Give the listener a moment; without this the first connect races it.
        $deadline = microtime(true) + 5;

        while (microtime(true) < $deadline) {
            $probe = @fsockopen('127.0.0.1', self::PORT, $errno, $errstr, 1);

            if ($probe !== false) {
                fclose($probe);

                return;
            }

            usleep(100_000);
        }

        self::markTestSkipped('The test RCON server did not start.');
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->client = self::getContainer()->get(RconClientInterface::class);
    }

    public function testReadsAMultiLineReply(): void
    {
        $reply = $this->client->execute($this->config(), 'players');

        self::assertStringContainsString('Players connected', $reply);
        self::assertStringContainsString('Bob', $reply);
        self::assertStringContainsString('Alice', $reply);
    }

    public function testProbeUsesTheReadOnlyPlayersCommand(): void
    {
        self::assertStringContainsString('Players connected', $this->client->probe($this->config()));
    }

    public function testSendsACommandWithArguments(): void
    {
        self::assertSame('Message sent.', $this->client->execute($this->config(), 'servermsg Hello world'));
    }

    public function testReturnsTheServersOwnWordingForUnknownCommands(): void
    {
        // Zomboid answers in free text, so an unknown command is a reply,
        // not an error the client should raise.
        self::assertStringContainsString('Unknown command', $this->client->execute($this->config(), 'nonsense'));
    }

    public function testRejectsAWrongPassword(): void
    {
        $this->expectException(RconAuthenticationFailed::class);
        $this->client->execute($this->config(password: 'wrong-password'), 'players');
    }

    public function testReportsAnUnreachableServer(): void
    {
        $this->expectException(RconUnreachable::class);
        $this->client->execute($this->config(port: 27099), 'players');
    }

    public function testRefusesAnEmptyCommand(): void
    {
        $this->expectException(\App\Server\Rcon\RconCommandFailed::class);
        $this->client->execute($this->config(), '   ');
    }

    private function config(?int $port = null, ?string $password = null): RconConfig
    {
        $config = new RconConfig(
            new GameServer('Test Server'),
            '127.0.0.1',
            $password ?? self::PASSWORD,
        );
        $config->setPort($port ?? self::PORT);

        return $config;
    }
}
