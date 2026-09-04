<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mail;

use App\Mail\DeliverabilityChecker;
use App\Mail\DnsResolver;
use PHPUnit\Framework\TestCase;

final class DeliverabilityCheckerTest extends TestCase
{
    public function testReportsNoSenderWhenNoFromAddressIsConfigured(): void
    {
        $result = $this->checker()->analyse(null, 'mail.your-server.de');

        self::assertSame('noSender', $result['verdict']);
        self::assertSame([], $result['checks']);
    }

    public function testReportsNoSenderWhenTheFromAddressHasNoDomain(): void
    {
        self::assertSame('noSender', $this->checker()->analyse('not-an-address', null)['verdict']);
    }

    public function testReportsAllThreeRecordsAsMissingOnADomainWithoutDns(): void
    {
        $result = $this->checker()->analyse('noreply@example.com', 'mail.your-server.de');

        self::assertSame('atRisk', $result['verdict']);
        self::assertSame(['spf', 'dkim', 'dmarc'], array_column($result['checks'], 'id'));
        self::assertSame(
            ['missing', 'missing', 'missing'],
            array_column($result['checks'], 'status'),
        );
    }

    public function testSuggestsAnSpfRecordNamingTheConfiguredMailProvider(): void
    {
        $result = $this->checker()->analyse('noreply@example.com', 'mail.your-server.de');

        self::assertSame('v=spf1 include:your-server.de ~all', $this->check($result, 'spf')['suggestedValue']);
    }

    public function testSuggestsNoSpfRecordWhenNoMailHostIsKnown(): void
    {
        $result = $this->checker()->analyse('noreply@example.com', null);

        self::assertNull($this->check($result, 'spf')['suggestedValue']);
    }

    public function testAcceptsAnSpfRecordThatIncludesTheProvider(): void
    {
        $result = $this
            ->checker(['example.com' => ['v=spf1 include:your-server.de ~all']])
            ->analyse('noreply@example.com', 'mail.your-server.de');

        self::assertSame('ok', $this->check($result, 'spf')['status']);
    }

    /**
     * RFC 7208 deprecated the "ptr" mechanism and several large receivers
     * ignore it, so a record leaning on it authorises nothing in practice.
     */
    public function testWarnsAboutAnSpfRecordRelyingOnPtr(): void
    {
        $result = $this
            ->checker(['example.com' => ['v=spf1 a mx ptr ?all']])
            ->analyse('noreply@example.com', 'mail.your-server.de');

        self::assertSame('warning', $this->check($result, 'spf')['status']);
        self::assertSame('spfUsesPtr', $this->check($result, 'spf')['reason']);
    }

    public function testWarnsAboutAnSpfRecordThatAuthorisesEverySender(): void
    {
        $result = $this
            ->checker(['example.com' => ['v=spf1 +all']])
            ->analyse('noreply@example.com', 'mail.your-server.de');

        self::assertSame('spfPassAll', $this->check($result, 'spf')['reason']);
    }

    public function testWarnsWhenAnSpfRecordNamesNeitherTheProviderNorAnyAddress(): void
    {
        $result = $this
            ->checker(['example.com' => ['v=spf1 a mx -all']])
            ->analyse('noreply@example.com', 'mail.your-server.de');

        self::assertSame('spfMayNotCoverSender', $this->check($result, 'spf')['reason']);
    }

    public function testFindsDkimUnderACommonSelectorAndNamesIt(): void
    {
        $result = $this
            ->checker(['selector1._domainkey.example.com' => ['v=DKIM1; k=rsa; p=MIIB']])
            ->analyse('noreply@example.com', 'mail.your-server.de');

        self::assertSame('ok', $this->check($result, 'dkim')['status']);
        self::assertSame('selector1', $this->check($result, 'dkim')['found']);
    }

    public function testWarnsAboutADmarcRecordAppliedToOnlyPartOfTheMail(): void
    {
        $result = $this
            ->checker(['_dmarc.example.com' => ['v=DMARC1; p=quarantine; pct=50']])
            ->analyse('noreply@example.com', 'mail.your-server.de');

        self::assertSame('warning', $this->check($result, 'dmarc')['status']);
        self::assertSame('dmarcPartialPercentage', $this->check($result, 'dmarc')['reason']);
    }

    public function testAcceptsADmarcRecordThatOnlyMonitors(): void
    {
        $result = $this
            ->checker(['_dmarc.example.com' => ['v=DMARC1; p=none; rua=mailto:a@example.com']])
            ->analyse('noreply@example.com', 'mail.your-server.de');

        self::assertSame('ok', $this->check($result, 'dmarc')['status']);
        self::assertSame('dmarcMonitorOnly', $this->check($result, 'dmarc')['reason']);
    }

    public function testCallsAFullySetUpDomainGood(): void
    {
        $result = $this->checker([
            'example.com' => ['v=spf1 include:your-server.de ~all'],
            'default._domainkey.example.com' => ['v=DKIM1; p=MIIB'],
            '_dmarc.example.com' => ['v=DMARC1; p=reject; rua=mailto:a@example.com'],
        ])->analyse('noreply@example.com', 'mail.your-server.de');

        self::assertSame('good', $result['verdict']);
    }

    public function testCallsADomainPartialWhenSomeRecordsAreThereAndOthersAreNot(): void
    {
        $result = $this->checker([
            'example.com' => ['v=spf1 include:your-server.de ~all'],
        ])->analyse('noreply@example.com', 'mail.your-server.de');

        self::assertSame('partial', $result['verdict']);
    }

    public function testIgnoresTxtRecordsThatAreNotTheOneBeingLookedFor(): void
    {
        $result = $this
            ->checker(['example.com' => ['google-site-verification=abc', 'v=spf1 include:your-server.de ~all']])
            ->analyse('noreply@example.com', 'mail.your-server.de');

        self::assertSame('ok', $this->check($result, 'spf')['status']);
    }

    /** @param array<string, list<string>> $records */
    private function checker(array $records = []): DeliverabilityChecker
    {
        return new DeliverabilityChecker(new StubDnsResolver($records));
    }

    /**
     * @param array{checks: list<array<string, mixed>>} $result
     *
     * @return array<string, mixed>
     */
    private function check(array $result, string $id): array
    {
        foreach ($result['checks'] as $check) {
            if ($check['id'] === $id) {
                return $check;
            }
        }

        self::fail(sprintf('No check with id "%s".', $id));
    }
}

final readonly class StubDnsResolver implements DnsResolver
{
    /** @param array<string, list<string>> $records */
    public function __construct(private array $records = [])
    {
    }

    /** @return list<string> */
    public function txt(string $name): array
    {
        return $this->records[$name] ?? [];
    }
}
