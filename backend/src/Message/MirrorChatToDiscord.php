<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Read the chat log and send what is public into Discord.
 *
 * Scheduled rather than streamed: the log is read over FTP, and a
 * long-lived connection would hold an FPM worker for as long as it
 * lasted (`docker/apache-vhost.conf:5`).
 */
final readonly class MirrorChatToDiscord
{
}
