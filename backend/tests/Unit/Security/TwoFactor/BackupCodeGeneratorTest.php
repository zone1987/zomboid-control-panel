<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security\TwoFactor;

use App\Entity\User;
use App\Security\TwoFactor\BackupCodeGenerator;
use PHPUnit\Framework\TestCase;

final class BackupCodeGeneratorTest extends TestCase
{
    public function testIssuesTenCodes(): void
    {
        self::assertCount(10, $this->generate());
    }

    public function testEveryCodeIsDifferent(): void
    {
        $codes = $this->generate();

        self::assertSame($codes, array_unique($codes));
    }

    public function testCodesAreShapedForTypingOut(): void
    {
        foreach ($this->generate() as $code) {
            self::assertMatchesRegularExpression('/^[0-9A-F]{5}-[0-9A-F]{5}$/', $code);
        }
    }

    /** A stolen database must not hand over usable codes. */
    public function testStoresHashesRatherThanTheCodes(): void
    {
        $user = new User('a@example.com', 'Test');
        $codes = (new BackupCodeGenerator())->generateFor($user);

        $property = new \ReflectionProperty(User::class, 'backupCodes');
        $stored = json_encode($property->getValue($user), JSON_THROW_ON_ERROR);

        self::assertStringStartsWith('["sha256:', $stored);

        foreach ($codes as $code) {
            self::assertStringNotContainsString($code, $stored);
        }
    }

    public function testAcceptsACodeThatWasIssued(): void
    {
        $user = new User('a@example.com', 'Test');
        $codes = (new BackupCodeGenerator())->generateFor($user);

        self::assertTrue($user->isBackupCode($codes[0]));
        self::assertTrue($user->isBackupCode($codes[9]));
    }

    public function testRefusesACodeThatWasNeverIssued(): void
    {
        $user = new User('a@example.com', 'Test');
        (new BackupCodeGenerator())->generateFor($user);

        self::assertFalse($user->isBackupCode('AAAAA-BBBBB'));
    }

    /** Typed off a printout, so case and spacing are forgiven. */
    public function testAcceptsACodeTypedInLowerCaseOrWithSpaces(): void
    {
        $user = new User('a@example.com', 'Test');
        $codes = (new BackupCodeGenerator())->generateFor($user);

        self::assertTrue($user->isBackupCode(strtolower($codes[0])));
        self::assertTrue($user->isBackupCode('  '.$codes[0].' '));
    }

    public function testUsingACodeRemovesOnlyThatOne(): void
    {
        $user = new User('a@example.com', 'Test');
        $codes = (new BackupCodeGenerator())->generateFor($user);

        $user->invalidateBackupCode($codes[3]);

        self::assertSame(9, $user->getBackupCodeCount());
        self::assertFalse($user->isBackupCode($codes[3]));
        self::assertTrue($user->isBackupCode($codes[4]));
    }

    /**
     * Codes issued before the move to SHA-256 were bcrypt, and have to
     * keep working until the set is replaced.
     */
    public function testStillAcceptsACodeStoredAsBcrypt(): void
    {
        $user = new User('a@example.com', 'Test');
        $user->setBackupCodes([password_hash('ABCDE-12345', PASSWORD_DEFAULT, ['cost' => 4])]);

        self::assertTrue($user->isBackupCode('ABCDE-12345'));
        self::assertFalse($user->isBackupCode('AAAAA-BBBBB'));
    }

    public function testRetiresABcryptCodeWhenItIsUsed(): void
    {
        $user = new User('a@example.com', 'Test');
        $user->setBackupCodes([password_hash('ABCDE-12345', PASSWORD_DEFAULT, ['cost' => 4])]);

        $user->invalidateBackupCode('ABCDE-12345');

        self::assertSame(0, $user->getBackupCodeCount());
    }

    /** Ten bcrypt hashes took two seconds, which a request cannot spare. */
    public function testIssuingASetIsFast(): void
    {
        $started = microtime(true);
        $this->generate();

        self::assertLessThan(0.2, microtime(true) - $started);
    }

    /** @return list<string> */
    private function generate(): array
    {
        return (new BackupCodeGenerator())->generateFor(new User('a@example.com', 'Test'));
    }
}
