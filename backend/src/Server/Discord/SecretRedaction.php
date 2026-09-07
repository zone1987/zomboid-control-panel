<?php

declare(strict_types=1);

namespace App\Server\Discord;

use App\Entity\AppSetting;
use App\Repository\AppSettingRepository;
use App\Repository\GameServerRepository;
use Psr\Log\LoggerInterface;

/**
 * Stops a secret leaving the panel inside a Discord message.
 *
 * The case is real rather than theoretical: `/server status` and a
 * console broadcast both relay whatever the game server answered, and a
 * server's reply can carry its own RCON or join password — Zomboid
 * prints `showoptions` in full to anybody who asks it.
 *
 * **Compared by exact value, never by pattern.** A regex guessing what a
 * password looks like is a new bug: it misses the ones that do not fit
 * and mangles innocent text that does. The panel already holds every
 * secret it knows, so it can look for those exactly.
 *
 * **A check that fails does not send.** If the secrets cannot be read at
 * all, the message is refused rather than sent unchecked — the failure
 * mode of a redactor that gives up is the one that leaks.
 */
final readonly class SecretRedaction
{
    /** Below this a "secret" is too short to search for safely. */
    private const MIN_LENGTH = 6;

    public const MASK = '[entfernt]';

    public function __construct(
        private AppSettingRepository $settings,
        private GameServerRepository $servers,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws DiscordException when the secrets cannot be established
     */
    public function scrub(string $text): string
    {
        $secrets = $this->secrets();

        foreach ($secrets as $secret) {
            $text = str_replace($secret, self::MASK, $text);
        }

        return $text;
    }

    /**
     * Every secret the panel holds, long enough to be searched for.
     *
     * @return list<string>
     *
     * @throws DiscordException
     */
    private function secrets(): array
    {
        try {
            $found = [];

            foreach (AppSetting::SECRET_KEYS as $key) {
                $value = $this->settings->find($key)?->getValue();

                if (is_string($value) && strlen($value) >= self::MIN_LENGTH) {
                    $found[] = $value;
                }
            }

            foreach ($this->servers->findAll() as $server) {
                foreach ([
                    $server->getRconConfig()?->getPassword(),
                    $server->getFtpConfig()?->getPassword(),
                ] as $password) {
                    if (is_string($password) && strlen($password) >= self::MIN_LENGTH) {
                        $found[] = $password;
                    }
                }
            }
        } catch (\Throwable $exception) {
            $this->logger->error('could not read the secrets to redact; refusing to send', [
                'error' => $exception->getMessage(),
            ]);

            throw new DiscordException('discord.redactionFailed', $exception->getMessage());
        }

        // Longest first, so a secret containing another is masked whole
        // rather than leaving its tail behind.
        usort($found, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return array_values(array_unique($found));
    }
}
