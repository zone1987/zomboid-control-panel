<?php

declare(strict_types=1);

namespace App\Security\Encryption;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;

final class EncryptedStringType extends Type
{
    public const NAME = 'encrypted_string';

    private static ?CredentialCipher $cipher = null;

    public static function setCipher(CredentialCipher $cipher): void
    {
        self::$cipher = $cipher;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getClobTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!\is_string($value)) {
            throw ConversionException::conversionFailedInvalidType($value, self::NAME, ['null', 'string']);
        }

        return self::cipher()->encrypt($value);
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!\is_string($value)) {
            throw ConversionException::conversionFailedInvalidType($value, self::NAME, ['null', 'string']);
        }

        return self::cipher()->decrypt($value);
    }

    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        return true;
    }

    private static function cipher(): CredentialCipher
    {
        return self::$cipher ?? throw new \LogicException(
            'The cipher was never injected into '.self::class.'; check EncryptionTypeInitializer.'
        );
    }
}
