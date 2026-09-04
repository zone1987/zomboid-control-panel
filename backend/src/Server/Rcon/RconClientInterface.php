<?php

declare(strict_types=1);

namespace App\Server\Rcon;

use App\Entity\RconConfig;

interface RconClientInterface
{
    /**
     * @throws RconAuthenticationFailed when the password is refused
     * @throws RconUnreachable         when the server cannot be reached
     * @throws RconCommandFailed       when the command itself goes wrong
     */
    public function execute(RconConfig $config, string $command): string;

    /**
     * Probes the connection with a harmless command.
     *
     * @throws RconAuthenticationFailed
     * @throws RconUnreachable
     */
    public function probe(RconConfig $config): string;
}
