<?php

declare(strict_types=1);

namespace App\Security\TwoFactor;

use App\Entity\User;

/**
 * Recovery codes are shown once and stored only as hashes; a lost set
 * cannot be recovered, only replaced.
 */
final class BackupCodeGenerator
{
    private const COUNT = 10;
    private const BYTES = 5;

    /**
     * @return list<string> the plaintext codes, to be shown once
     */
    public function generateFor(User $user): array
    {
        $plain = [];
        $hashed = [];

        for ($i = 0; $i < self::COUNT; ++$i) {
            $code = $this->formatCode(bin2hex(random_bytes(self::BYTES)));
            $plain[] = $code;
            $hashed[] = password_hash($code, PASSWORD_DEFAULT);
        }

        $user->setBackupCodes($hashed);

        return $plain;
    }

    private function formatCode(string $raw): string
    {
        return strtoupper(substr($raw, 0, 5).'-'.substr($raw, 5, 5));
    }
}
