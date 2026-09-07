<?php

declare(strict_types=1);

namespace App\Server\Config;

/**
 * A write that was not performed, with the reason as a message key.
 *
 * Separate from StorageException: nothing went wrong with the transfer,
 * the request itself was not something this editor will do — adding a
 * setting, removing one, or naming a key the file does not hold.
 */
final class ConfigWriteRefused extends \RuntimeException
{
    /** @param list<string> $keys */
    public function __construct(
        private readonly string $messageKey,
        private readonly array $keys = [],
    ) {
        parent::__construct(sprintf(
            '%s%s',
            $messageKey,
            $keys === [] ? '' : ': '.implode(', ', $keys),
        ));
    }

    public function messageKey(): string
    {
        return $this->messageKey;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return $this->keys;
    }
}
