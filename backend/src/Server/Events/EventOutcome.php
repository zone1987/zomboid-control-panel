<?php

declare(strict_types=1);

namespace App\Server\Events;

/** What an event action sent, and what the server said back. */
final readonly class EventOutcome
{
    public function __construct(
        public string $actionId,
        public string $command,
        public string $reply,
        /**
         * Set when the answer already carries a verdict. The bridge says
         * plainly whether it worked; RCON never does, so its outcomes
         * leave this null and the wording decides.
         */
        private ?bool $failed = null,
    ) {
    }

    /**
     * Zomboid answers in prose and never with a status, so the wording it
     * uses for a refusal is the only signal there is.
     */
    public function failed(): bool
    {
        if ($this->failed !== null) {
            return $this->failed;
        }

        $reply = strtolower(trim($this->reply));

        if ($reply === '' || $reply === 'error') {
            return true;
        }

        foreach (['not found', 'invalid', 'unknown', 'no such', 'pass a username', 'specify a player'] as $needle) {
            if (str_contains($reply, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'action' => $this->actionId,
            'command' => $this->command,
            'reply' => $this->reply,
            'failed' => $this->failed(),
        ];
    }
}
