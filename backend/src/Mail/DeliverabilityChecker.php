<?php

declare(strict_types=1);

namespace App\Mail;

use App\Entity\AppSetting;
use App\Settings\SettingsProvider;

/**
 * Checks the DNS records that decide whether mail reaches an inbox.
 *
 * Most people self-hosting this will never have heard of SPF or DKIM,
 * and will conclude the panel is broken when their invitations vanish.
 * Naming the missing record — and the exact value to publish — is the
 * difference between a puzzle and a five-minute fix.
 */
final readonly class DeliverabilityChecker
{
    public const STATUS_OK = 'ok';
    public const STATUS_WARNING = 'warning';
    public const STATUS_MISSING = 'missing';

    private const DKIM_SELECTORS = [
        'default', 'dkim', 'mail', 'selector1', 'selector2',
        's1', 's2', 'google', 'k1', 'mandrill', 'zoho', 'protonmail',
    ];

    public function __construct(private DnsResolver $dns)
    {
    }

    /**
     * @return array{
     *     domain: string|null,
     *     senderAddress: string|null,
     *     mailHost: string|null,
     *     verdict: string,
     *     checks: list<array{
     *         id: string,
     *         status: string,
     *         reason: string,
     *         found: string|null,
     *         recordName: string|null,
     *         suggestedValue: string|null
     *     }>
     * }
     */
    public function check(SettingsProvider $settings): array
    {
        return $this->analyse(
            $settings->get(AppSetting::MAIL_FROM_ADDRESS),
            $settings->get(AppSetting::MAIL_HOST),
        );
    }

    /**
     * @return array{
     *     domain: string|null,
     *     senderAddress: string|null,
     *     mailHost: string|null,
     *     verdict: string,
     *     checks: list<array{
     *         id: string,
     *         status: string,
     *         reason: string,
     *         found: string|null,
     *         recordName: string|null,
     *         suggestedValue: string|null
     *     }>
     * }
     */
    public function analyse(?string $sender, ?string $mailHost): array
    {
        $domain = $this->domainOf($sender);

        if ($domain === null) {
            return [
                'domain' => null,
                'senderAddress' => $sender,
                'mailHost' => $mailHost,
                'verdict' => 'noSender',
                'checks' => [],
            ];
        }

        $checks = [
            $this->checkSpf($domain, $mailHost),
            $this->checkDkim($domain),
            $this->checkDmarc($domain),
        ];

        return [
            'domain' => $domain,
            'senderAddress' => $sender,
            'mailHost' => $mailHost,
            'verdict' => $this->verdict($checks),
            'checks' => $checks,
        ];
    }

    /**
     * @return array{id: string, status: string, reason: string, found: string|null, recordName: string|null, suggestedValue: string|null}
     */
    private function checkSpf(string $domain, ?string $mailHost): array
    {
        $record = $this->firstMatching($domain, '/^v=spf1\b/i');
        $suggested = $this->suggestedSpf($mailHost);

        if ($record === null) {
            return $this->result('spf', self::STATUS_MISSING, 'spfMissing', null, $domain, $suggested);
        }

        if (preg_match('/\ball\b/i', $record) !== 1) {
            return $this->result('spf', self::STATUS_WARNING, 'spfNoAll', $record, $domain, $suggested);
        }

        if (preg_match('/\+all\b/i', $record) === 1) {
            return $this->result('spf', self::STATUS_WARNING, 'spfPassAll', $record, $domain, $suggested);
        }

        // "ptr" was deprecated by RFC 7208; several large receivers ignore
        // it outright, so a record leaning on it authorises nothing.
        if (preg_match('/\bptr\b/i', $record) === 1) {
            return $this->result('spf', self::STATUS_WARNING, 'spfUsesPtr', $record, $domain, $suggested);
        }

        if ($this->spfNamesSender($record, $mailHost) === false) {
            return $this->result('spf', self::STATUS_WARNING, 'spfMayNotCoverSender', $record, $domain, $suggested);
        }

        return $this->result('spf', self::STATUS_OK, 'spfOk', $record, $domain, null);
    }

    /**
     * @return array{id: string, status: string, reason: string, found: string|null, recordName: string|null, suggestedValue: string|null}
     */
    private function checkDkim(string $domain): array
    {
        foreach (self::DKIM_SELECTORS as $selector) {
            $name = sprintf('%s._domainkey.%s', $selector, $domain);

            if ($this->firstMatching($name, '/\bp=/i') !== null) {
                return $this->result('dkim', self::STATUS_OK, 'dkimOk', $selector, $name, null);
            }
        }

        // Selectors cannot be enumerated over DNS, so a negative result is
        // "none of the usual names", never a proof of absence.
        return $this->result('dkim', self::STATUS_MISSING, 'dkimMissing', null, null, null);
    }

    /**
     * @return array{id: string, status: string, reason: string, found: string|null, recordName: string|null, suggestedValue: string|null}
     */
    private function checkDmarc(string $domain): array
    {
        $name = '_dmarc.'.$domain;
        $record = $this->firstMatching($name, '/^v=DMARC1\b/i');
        $suggested = 'v=DMARC1; p=none; rua=mailto:postmaster@'.$domain;

        if ($record === null) {
            return $this->result('dmarc', self::STATUS_MISSING, 'dmarcMissing', null, $name, $suggested);
        }

        if (preg_match('/\bpct=(\d+)/i', $record, $matches) === 1 && (int) $matches[1] < 100) {
            return $this->result('dmarc', self::STATUS_WARNING, 'dmarcPartialPercentage', $record, $name, $suggested);
        }

        if (preg_match('/\bp=\s*none\b/i', $record) === 1) {
            return $this->result('dmarc', self::STATUS_OK, 'dmarcMonitorOnly', $record, $name, null);
        }

        return $this->result('dmarc', self::STATUS_OK, 'dmarcOk', $record, $name, null);
    }

    /**
     * The sending host matters more than the syntax: a record listing only
     * the web server does not cover mail sent through the provider's relay,
     * which is the usual reason a correct-looking SPF still fails.
     */
    private function spfNamesSender(string $record, ?string $host): ?bool
    {
        if ($host === null || $host === '') {
            return null;
        }

        $registrable = $this->registrablePart($host);

        if ($registrable !== null && stripos($record, $registrable) !== false) {
            return true;
        }

        // An include or an explicit address range may well cover the relay
        // without naming it literally; only "a"/"mx" alone is doubtful.
        if (preg_match('/\b(include:|ip4:|ip6:|redirect=)/i', $record) === 1) {
            return true;
        }

        return false;
    }

    private function suggestedSpf(?string $host): ?string
    {
        $registrable = $host === null || $host === '' ? null : $this->registrablePart($host);

        return $registrable === null
            ? null
            : sprintf('v=spf1 include:%s ~all', $registrable);
    }

    /** Reduces "mail.your-server.de" to "your-server.de". */
    private function registrablePart(string $host): ?string
    {
        $labels = explode('.', trim($host, '.'));

        if (\count($labels) < 2) {
            return null;
        }

        return implode('.', \array_slice($labels, -2));
    }

    /** @param list<array{status: string, ...}> $checks */
    private function verdict(array $checks): string
    {
        $statuses = array_column($checks, 'status');

        if (\in_array(self::STATUS_MISSING, $statuses, true)) {
            return \count(array_filter($statuses, static fn (string $s): bool => $s === self::STATUS_OK)) === 0
                ? 'atRisk'
                : 'partial';
        }

        return \in_array(self::STATUS_WARNING, $statuses, true) ? 'partial' : 'good';
    }

    private function firstMatching(string $name, string $pattern): ?string
    {
        foreach ($this->dns->txt($name) as $value) {
            if (preg_match($pattern, $value) === 1) {
                return $value;
            }
        }

        return null;
    }

    private function domainOf(?string $address): ?string
    {
        if ($address === null) {
            return null;
        }

        $position = strrpos($address, '@');

        if ($position === false) {
            return null;
        }

        $domain = substr($address, $position + 1);

        return $domain === '' ? null : strtolower($domain);
    }

    /**
     * @return array{id: string, status: string, reason: string, found: string|null, recordName: string|null, suggestedValue: string|null}
     */
    private function result(
        string $id,
        string $status,
        string $reason,
        ?string $found,
        ?string $recordName,
        ?string $suggestedValue,
    ): array {
        return [
            'id' => $id,
            'status' => $status,
            'reason' => $reason,
            'found' => $found,
            'recordName' => $recordName,
            'suggestedValue' => $suggestedValue,
        ];
    }
}
