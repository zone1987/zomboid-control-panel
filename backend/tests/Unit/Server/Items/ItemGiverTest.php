<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Items;

use App\Entity\GameServer;
use App\Entity\RconConfig;
use App\Server\Items\ItemGiver;
use App\Server\Rcon\RconClientInterface;
use PHPUnit\Framework\TestCase;

final class ItemGiverTest extends TestCase
{
    public function testSendsOneCommandForASmallAmount(): void
    {
        $rcon = new SpyRcon();

        $this->giver($rcon)->give($this->server(), 'bob', [['type' => 'Base.Axe', 'count' => 5]]);

        self::assertSame(['additem "bob" "Base.Axe" 5'], $rcon->sent);
    }

    /**
     * The server caps a single additem at 100 and mentions it only on its
     * own console, so asking for 250 in one command would quietly deliver
     * 100 and report success.
     */
    public function testSplitsAnAmountAboveTheServersOwnLimit(): void
    {
        $rcon = new SpyRcon();

        $this->giver($rcon)->give($this->server(), 'bob', [['type' => 'Base.Nails', 'count' => 250]]);

        self::assertSame([
            'additem "bob" "Base.Nails" 100',
            'additem "bob" "Base.Nails" 100',
            'additem "bob" "Base.Nails" 50',
        ], $rcon->sent);
    }

    public function testSendsExactlyOneCommandForTheLimitItself(): void
    {
        $rcon = new SpyRcon();

        $this->giver($rcon)->give($this->server(), 'bob', [['type' => 'Base.Nails', 'count' => 100]]);

        self::assertCount(1, $rcon->sent);
    }

    public function testStopsSplittingOnceTheServerRefusesTheItem(): void
    {
        $rcon = new SpyRcon();
        $rcon->reply = "Item Base.Nope doesn't exist.";

        $result = $this->giver($rcon)->give($this->server(), 'bob', [['type' => 'Base.Nope', 'count' => 250]]);

        self::assertCount(1, $rcon->sent);
        self::assertTrue($result[0]['failed']);
    }

    public function testReportsSuccessWhenTheServerAcceptsIt(): void
    {
        $result = $this->giver(new SpyRcon())->give(
            $this->server(),
            'bob',
            [['type' => 'Base.Axe', 'count' => 1]],
        );

        self::assertFalse($result[0]['failed']);
        self::assertSame('Base.Axe', $result[0]['type']);
        self::assertSame(1, $result[0]['count']);
    }

    public function testGivesSeveralItemsInOneGo(): void
    {
        $rcon = new SpyRcon();

        $this->giver($rcon)->give($this->server(), 'bob', [
            ['type' => 'Base.Axe', 'count' => 1],
            ['type' => 'Base.Nails', 'count' => 2],
        ]);

        self::assertCount(2, $rcon->sent);
    }

    public function testRefusesAnAmountBeyondAnythingSensible(): void
    {
        $rcon = new SpyRcon();

        $result = $this->giver($rcon)->give($this->server(), 'bob', [['type' => 'Base.Axe', 'count' => 99999]]);

        self::assertSame(ItemGiver::MAX_TOTAL, $result[0]['count']);
        self::assertCount(10, $rcon->sent);
    }

    public function testTreatsAnAmountBelowOneAsOne(): void
    {
        $rcon = new SpyRcon();

        $this->giver($rcon)->give($this->server(), 'bob', [['type' => 'Base.Axe', 'count' => 0]]);

        self::assertSame(['additem "bob" "Base.Axe" 1'], $rcon->sent);
    }

    /** A quote in the name would end the argument and the rest would run. */
    public function testSkipsATypeThatIsNotAnItemName(): void
    {
        $rcon = new SpyRcon();

        $result = $this->giver($rcon)->give($this->server(), 'bob', [
            ['type' => 'Base.Axe" 1; quit "', 'count' => 1],
        ]);

        self::assertSame([], $rcon->sent);
        self::assertSame([], $result);
    }

    public function testStripsAQuoteFromTheUsername(): void
    {
        $rcon = new SpyRcon();

        $this->giver($rcon)->give($this->server(), 'bo"b', [['type' => 'Base.Axe', 'count' => 1]]);

        self::assertSame(['additem "bob" "Base.Axe" 1'], $rcon->sent);
    }

    public function testRecognisesTheServersWordingForAMissingItem(): void
    {
        self::assertTrue(ItemGiver::looksLikeFailure("Item Base.Nope doesn't exist."));
        self::assertTrue(ItemGiver::looksLikeFailure('No such user'));
        self::assertFalse(ItemGiver::looksLikeFailure("admin added item Base.Axe in bob's inventory"));
    }

    private function giver(SpyRcon $rcon): ItemGiver
    {
        return new ItemGiver($rcon);
    }

    private function server(): GameServer
    {
        $server = new GameServer('Test');
        new RconConfig($server, '127.0.0.1', 'secret');

        return $server;
    }
}

final class SpyRcon implements RconClientInterface
{
    /** @var list<string> */
    public array $sent = [];

    public string $reply = "admin added item in bob's inventory";

    public function execute(RconConfig $config, string $command): string
    {
        $this->sent[] = $command;

        return $this->reply;
    }

    public function probe(RconConfig $config): string
    {
        return 'Players connected (0):';
    }
}
