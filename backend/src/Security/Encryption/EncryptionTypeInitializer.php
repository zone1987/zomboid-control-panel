<?php

declare(strict_types=1);

namespace App\Security\Encryption;

use Symfony\Component\DependencyInjection\Attribute\AsEventListener;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Doctrine instantiates types statically, so the cipher cannot reach
 * EncryptedStringType through constructor injection.
 */
final readonly class EncryptionTypeInitializer
{
    public function __construct(private CredentialCipher $cipher)
    {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 4096)]
    public function onKernelRequest(RequestEvent $event): void
    {
        EncryptedStringType::setCipher($this->cipher);
    }

    #[AsEventListener(event: ConsoleEvents::COMMAND, priority: 4096)]
    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        EncryptedStringType::setCipher($this->cipher);
    }
}
