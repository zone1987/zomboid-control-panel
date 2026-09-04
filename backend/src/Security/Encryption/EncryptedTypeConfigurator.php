<?php

declare(strict_types=1);

namespace App\Security\Encryption;

use Doctrine\Bundle\DoctrineBundle\Dbal\ManagerRegistryAwareConnectionProvider;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver;

/**
 * Doctrine builds types through a static registry, so the cipher cannot be
 * constructor-injected. This middleware runs whenever a connection is created,
 * which covers HTTP requests, console commands and tests alike.
 */
final readonly class EncryptedTypeConfigurator implements Middleware
{
    public function __construct(private CredentialCipher $cipher)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        EncryptedStringType::setCipher($this->cipher);

        return $driver;
    }
}
