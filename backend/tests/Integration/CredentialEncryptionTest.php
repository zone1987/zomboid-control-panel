<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\GameServer;
use App\Entity\RconConfig;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CredentialEncryptionTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    public function testRconPasswordIsNotReadableInTheDatabase(): void
    {
        $server = new GameServer('Test Server');
        new RconConfig($server, 'zomboid.example.com', 'super-secret-rcon');

        $this->em->persist($server);
        $this->em->persist($server->getRconConfig());
        $this->em->flush();

        $stored = $this->em->getConnection()->fetchOne(
            'SELECT password FROM rcon_config WHERE id = ?',
            [$server->getRconConfig()->getId()->toRfc4122()],
        );

        self::assertIsString($stored);
        self::assertStringNotContainsString('super-secret-rcon', $stored);
        self::assertStringStartsWith('v1:', $stored);
    }

    public function testRconPasswordRoundTripsThroughDoctrine(): void
    {
        $server = new GameServer('Round Trip Server');
        $config = new RconConfig($server, 'zomboid.example.com', 'round-trip-secret');

        $this->em->persist($server);
        $this->em->persist($config);
        $this->em->flush();

        $id = $config->getId();
        $this->em->clear();

        $reloaded = $this->em->find(RconConfig::class, $id);

        self::assertNotNull($reloaded);
        self::assertSame('round-trip-secret', $reloaded->getPassword());
    }
}
