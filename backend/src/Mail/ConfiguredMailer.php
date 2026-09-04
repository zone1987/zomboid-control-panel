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

    public const ENCRYPTION_MODES = ['tls', 'ssl', 'none'];

    public function isConfigured(): bool
    {
        return $this->dsn() !== null;
    }

    /**
     * Builds the DSN from individual fields.
     *
     * Asking an operator for a URL with percent-encoded credentials is a
     * needless trap — an @ or # in a password silently breaks it. The
     * fields are entered separately and assembled here; a DSN entered
     * directly still wins, for anyone who prefers one.
     */
    public function dsn(): ?string
    {
        $explicit = $this->settings->get(AppSetting::MAILER_DSN);

        if ($explicit !== null && !str_starts_with($explicit, 'null://')) {
            return $explicit;
        }

        $host = $this->settings->get(AppSetting::MAIL_HOST);

        if ($host === null) {
            return null;
        }

        $encryption = $this->settings->get(AppSetting::MAIL_ENCRYPTION) ?? 'tls';
        $scheme = $encryption === 'ssl' ? 'smtps' : 'smtp';

        $username = $this->settings->get(AppSetting::MAIL_USERNAME);
        $password = $this->settings->get(AppSetting::MAIL_PASSWORD);

        $credentials = '';

        if ($username !== null) {
            $credentials = rawurlencode($username);

            if ($password !== null) {
                $credentials .= ':'.rawurlencode($password);
            }

            $credentials .= '@';
        }

        $port = $this->settings->get(AppSetting::MAIL_PORT);
        $dsn = sprintf('%s://%s%s', $scheme, $credentials, $host);

        if ($port !== null && $port !== '') {
            $dsn .= ':'.(int) $port;
        }

        if ($encryption === 'none') {
            // Symfony would otherwise try STARTTLS and fail against a
            // server that does not offer it.
            $dsn .= '?require_tls=false';
        }

        return $dsn;
    }

    /**
     * @throws MailNotConfigured        when no usable SMTP account is set
     * @throws TransportExceptionInterface when the server refuses the message
     */
    public function send(Email $email): void
    {
        $dsn = $this->dsn();

        if ($dsn === null) {
            throw new MailNotConfigured();
        }

        $email->from($this->sender());

        new Mailer(Transport::fromDsn($dsn))->send($email);
    }

    /**
     * Sends a message to the address given, so an operator can confirm the
     * settings work before relying on them for invitations.
     *
     * @throws MailNotConfigured
     * @throws TransportExceptionInterface
     */
    public function sendTestMessage(string $recipient): void
    {
        $this->send(
            new Email()
                ->to($recipient)
                ->subject('ZomboidControl test message')
                ->text("This is a test message from ZomboidControl.\n\n"
                    ."If you are reading it, outgoing mail works.\n"),
        );
    }

    public function sender(): Address
    {
        return new Address(
            $this->settings->get(AppSetting::MAIL_FROM_ADDRESS) ?? 'noreply@localhost',
            $this->settings->get(AppSetting::MAIL_FROM_NAME) ?? 'ZomboidControl',
        );
    }
}
