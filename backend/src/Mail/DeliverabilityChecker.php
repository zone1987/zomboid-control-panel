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
 * Naming the missing record is the difference between a puzzle and a
 * five-minute fix.
 */
final readonly class DeliverabilityChecker
{
    public function __construct(private SettingsProvider $settings)
    {
    }

    /**
     * @return array{
     *     domain: string|null,
     *     spf: array{present: bool, value: string|null, coversSender: bool|null},
     *     dmarc: array{present: bool, value: string|null},
     *     dkim: array{present: bool, selectors: list<string>},
     *     verdict: string
     * }
     */
    public function check(): array
    {
        $from = $this->settings->get(AppSetting::MAIL_FROM_ADDRESS);
        $domain = $from === null ? null : substr(strrchr($from, '@') ?: '', 1);

        if ($domain === null || $domain === '' || $domain === false) {
            return $this->empty();
        }

        $spf = $this->firstTxtMatching($domain, '/^v=spf1\b/i');
        $dmarc = $this->firstTxtMatching('_dmarc.'.$domain, '/^v=DMARC1\b/i');
        $dkim = $this->findDkimSelectors($domain);

        return [
            'domain' => $domain,
            'spf' => [
                'present' => $spf !== null,
                'value' => $spf,
                'coversSender' => $spf === null ? null : $this->spfCoversSender($spf),
            ],
            'dmarc' => ['present' => $dmarc !== null, 'value' => $dmarc],
            'dkim' => ['present' => $dkim !== [], 'selectors' => $dkim],
            'verdict' => $this->verdict($spf, $dmarc, $dkim),
        ];
    }

    /**
     * @param list<string> $dkim
     */
    private function verdict(?string $spf, ?string $dmarc, array $dkim): string
    {
        if ($spf === null && $dkim === []) {
            return 'atRisk';
        }

        if ($spf === null || $dkim === [] || $dmarc === null) {
            return 'partial';
        }

        return 'good';
    }

    /**
     * The sending host matters more than the syntax: a record that lists
     * only the web server will not cover mail sent through the provider's
     * relay, which is the usual reason a correct-looking SPF still fails.
     */
    private function spfCoversSender(string $spf): ?bool
    {
        $host = $this->settings->get(AppSetting::MAIL_HOST);

        if ($host === null) {
            return null;
        }

        // An include or a broad "a"/"mx" may well cover it; only a record
        // that names nothing relevant is reported as a likely problem.
        if (preg_match('/\binclude:/i', $spf) === 1) {
            return true;
        }

        return preg_match('/\b(a|mx|ip4:|ip6:)\b/i', $spf) === 1 ? null : false;
    }

    /** @return list<string> */
    private function findDkimSelectors(string $domain): array
    {
        // No way to enumerate selectors, so the common ones are probed.
        $candidates = ['default', 'dkim', 'mail', 'selector1', 'selector2', 's1', 's2', 'google', 'k1'];
        $found = [];

        foreach ($candidates as $selector) {
            if ($this->firstTxtMatching(sprintf('%s._domainkey.%s', $selector, $domain), '/\bp=/i') !== null) {
                $found[] = $selector;
            }
        }

        return $found;
    }

    private function firstTxtMatching(string $name, string $pattern): ?string
    {
        $records = @dns_get_record($name, DNS_TXT);

        if ($records === false) {
            return null;
        }

        foreach ($records as $record) {
            $value = $record['txt'] ?? '';

            if (\is_string($value) && preg_match($pattern, $value) === 1) {
                return $value;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function empty(): array
    {
        return [
            'domain' => null,
            'spf' => ['present' => false, 'value' => null, 'coversSender' => null],
            'dmarc' => ['present' => false, 'value' => null],
            'dkim' => ['present' => false, 'selectors' => []],
            'verdict' => 'noSender',
        ];
    }
}
