<?php

declare(strict_types=1);

namespace App\Mail;

use App\Entity\AppSetting;
use App\Settings\SettingsProvider;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends through the SMTP account configured in the interface.
 *
 * Symfony's mailer resolves its DSN when the container is compiled, which
 * cannot see credentials entered later, so the transport is built here.
 */
final class ConfiguredMailer
{
    public function __construct(private readonly SettingsProvider $settings)
    {
    }

    public function isConfigured(): bool
    {
        $dsn = $this->settings->get(AppSetting::MAILER_DSN);

        return $dsn !== null && !str_starts_with($dsn, 'null://');
    }

    /**
     * @throws MailNotConfigured        when no usable SMTP account is set
     * @throws TransportExceptionInterface when the server refuses the message
     */
    public function send(Email $email): void
    {
        $dsn = $this->settings->get(AppSetting::MAILER_DSN);

        if ($dsn === null) {
            throw new MailNotConfigured();
        }

        $email->from($this->sender());

        new Mailer(Transport::fromDsn($dsn))->send($email);
    }

    public function sender(): Address
    {
        return new Address(
            $this->settings->get(AppSetting::MAIL_FROM_ADDRESS) ?? 'noreply@localhost',
            $this->settings->get(AppSetting::MAIL_FROM_NAME) ?? 'ZomboidControl',
        );
    }
}
