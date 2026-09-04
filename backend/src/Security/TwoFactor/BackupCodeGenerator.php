<?php

declare(strict_types=1);

namespace App\Security\TwoFactor;

use App\Entity\User;

/**
 * Recovery codes are shown once and stored only as hashes; a lost set
 * cannot be recovered, only replaced.
 *
 * Hashed with SHA-256 rather than bcrypt. A password needs a slow hash
 * because people choose guessable ones; these codes are 40 bits from
 * random_bytes, so there is nothing to guess and no dictionary to run.
 * Ten bcrypt hashes at the default cost took two seconds in one request,
 * which is a long wait for setting up two-factor.
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
            $hashed[] = self::hash($code);
        }

        $user->setBackupCodes($hashed);

        return $plain;
    }

    public static function hash(string $code): string
    {
        return 'sha256:'.hash('sha256', self::normalise($code));
    }

    /**
     * Typed by hand from a printout, so case and stray spaces are the
     * user's problem to have, not to solve.
     */
    public static function normalise(string $code): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim($code)) ?? '');
    }

    private function formatCode(string $raw): string
    {
        return strtoupper(substr($raw, 0, 5).'-'.substr($raw, 5, 5));
    }
}
