<?php

declare(strict_types=1);

namespace App\Tests\Unit\Panel;

use App\Entity\AppSetting;
use App\Panel\DeployProbe;
use App\Panel\DeployProbeVerdict;
use App\Panel\DeploymentStatus;
use App\Repository\AppSettingRepository;
use App\Settings\SettingsProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Checking the platform without deploying to it.
 *
 * The test button used to call the deploy hook, so asking "would this
 * work" started a real rollout and restarted the panel. Coolify's
 * `GET /applications/{uuid}` reads and changes nothing.
 */
final class DeployProbeTest extends TestCase
{
    private const HOOK = 'https://coolify.example.com/api/v1/deploy?uuid=7yjy9uoz032inopxjf2mzvcl&force=false';

    public function testReadsTheApplicationTheWebhookAlreadyNames(): void
    {
        self::assertSame(
            'https://coolify.example.com/api/v1/applications/7yjy9uoz032inopxjf2mzvcl',
            DeployProbe::readUrlFrom(self::HOOK),
        );
    }

    public function testKeepsANonStandardPort(): void
    {
        self::assertSame(
            'https://coolify.example.com:8000/api/v1/applications/abcdef123456',
            DeployProbe::readUrlFrom('https://coolify.example.com:8000/api/v1/deploy?uuid=abcdef123456'),
        );
    }

    /** The hook accepts a list; a single-application panel reads the first. */
    public function testTakesTheFirstOfSeveralUuids(): void
    {
        self::assertSame(
            'https://coolify.example.com/api/v1/applications/aaaaaaaaaaaa',
            DeployProbe::readUrlFrom('https://coolify.example.com/api/v1/deploy?uuid=aaaaaaaaaaaa,bbbbbbbbbbbb'),
        );
    }

    public function testRefusesAHookThatNamesNoApplication(): void
    {
        self::assertNull(DeployProbe::readUrlFrom('https://coolify.example.com/api/v1/deploy?tag=v1.2.3'));
        self::assertNull(DeployProbe::readUrlFrom('https://coolify.example.com/api/v1/deploy'));
    }

    public function testRefusesSomethingThatIsNotAnHttpAddress(): void
    {
        self::assertNull(DeployProbe::readUrlFrom('file:///etc/passwd?uuid=abcdef123456'));
        self::assertNull(DeployProbe::readUrlFrom('not a url at all'));
    }

    /** A uuid is a path segment; anything shaped otherwise is refused. */
    public function testRefusesAUuidThatCouldChangeThePath(): void
    {
        self::assertNull(DeployProbe::readUrlFrom('https://coolify.example.com/api/v1/deploy?uuid=../../secrets'));
        self::assertNull(DeployProbe::readUrlFrom('https://coolify.example.com/api/v1/deploy?uuid=abc'));
    }

    public function testSaysReadyAndNamesTheApplication(): void
    {
        $verdict = $this->probe(new MockResponse(
            json_encode(['uuid' => '7yjy9uoz032inopxjf2mzvcl', 'name' => 'zomboid-control'], \JSON_THROW_ON_ERROR),
            ['http_code' => 200],
        ));

        self::assertSame(DeployProbeVerdict::READY, $verdict->state);
        self::assertSame('zomboid-control', $verdict->applicationName);
        self::assertTrue($verdict->looksReady());
    }

    /** The one that must not read as broken: deploy without read. */
    public function testAForbiddenReadStillCountsAsDeployable(): void
    {
        $verdict = $this->probe(new MockResponse('{"message":"missing permission read"}', ['http_code' => 403]));

        self::assertSame(DeployProbeVerdict::NO_READ_PERMISSION, $verdict->state);
        self::assertTrue(
            $verdict->looksReady(),
            'a token that may deploy but not read can still deploy',
        );
    }

    public function testSaysSoWhenTheTokenIsRejected(): void
    {
        $verdict = $this->probe(new MockResponse('{"message":"Unauthenticated."}', ['http_code' => 401]));

        self::assertSame(DeployProbeVerdict::TOKEN_REJECTED, $verdict->state);
        self::assertFalse($verdict->looksReady());
        self::assertSame(401, $verdict->status);
    }

    public function testSaysSoWhenNoApplicationCarriesThatUuid(): void
    {
        $verdict = $this->probe(new MockResponse('{"message":"Not found."}', ['http_code' => 404]));

        self::assertSame(DeployProbeVerdict::NOT_FOUND, $verdict->state);
        self::assertFalse($verdict->looksReady());
    }

    public function testSaysSoWhenTheHostDoesNotAnswer(): void
    {
        $client = new MockHttpClient(static function (): never {
            throw new \Symfony\Component\HttpClient\Exception\TransportException('connection refused');
        });

        $verdict = (new DeployProbe($client, $this->settings(self::HOOK, 'tok'), new NullLogger()))->probe();

        self::assertSame(DeployProbeVerdict::UNREACHABLE, $verdict->state);
        self::assertNull($verdict->status);
    }

    public function testSaysSoWhenNothingIsConfigured(): void
    {
        $client = new MockHttpClient([]);

        $verdict = (new DeployProbe($client, $this->settings(null, null), new NullLogger()))->probe();

        self::assertSame(DeployProbeVerdict::NOT_CONFIGURED, $verdict->state);
    }

    /** An unexpected code is its own state, never bent into a known one. */
    public function testKeepsAnUnrecognisedCodeAsItsOwnState(): void
    {
        $verdict = $this->probe(new MockResponse('gateway trouble', ['http_code' => 502]));

        self::assertSame(DeployProbeVerdict::REFUSED, $verdict->state);
        self::assertSame(502, $verdict->status);
        self::assertFalse($verdict->looksReady());
    }

    /** It must GET the read address, never POST the hook. */
    public function testNeverCallsTheDeployHook(): void
    {
        $seen = [];

        $client = new MockHttpClient(static function (string $method, string $url) use (&$seen): MockResponse {
            $seen[] = $method.' '.$url;

            return new MockResponse('{"name":"x"}', ['http_code' => 200]);
        });

        (new DeployProbe($client, $this->settings(self::HOOK, 'tok'), new NullLogger()))->probe();

        self::assertSame(
            ['GET https://coolify.example.com/api/v1/applications/7yjy9uoz032inopxjf2mzvcl'],
            $seen,
        );
    }

    public function testSendsTheTokenAsABearerHeader(): void
    {
        $headers = [];

        $client = new MockHttpClient(static function (string $m, string $u, array $options) use (&$headers): MockResponse {
            $headers = $options['headers'] ?? [];

            return new MockResponse('{"name":"x"}', ['http_code' => 200]);
        });

        (new DeployProbe($client, $this->settings(self::HOOK, 'secret-token'), new NullLogger()))->probe();

        self::assertContains('Authorization: Bearer secret-token', $headers);
    }

    /**
     * The body was truncated to 500 characters before being parsed, so
     * the real answer -- 8 kB of application -- became invalid JSON and
     * the name silently came back null with a 200 beside it.
     */
    public function testReadsTheNameOutOfAnAnswerLongerThanTheDetailLimit(): void
    {
        $payload = [
            'uuid' => '7yjy9uoz032inopxjf2mzvcl',
            'name' => 'Zomboid Control Panel',
            'status' => 'running:healthy',
        ];

        // Coolify answers with roughly eighty fields; the padding stands
        // in for them so the body is far past any truncation limit.
        for ($i = 0; $i < 80; ++$i) {
            $payload['filler_'.$i] = str_repeat('x', 60);
        }

        $verdict = $this->probe(new MockResponse(
            json_encode($payload, \JSON_THROW_ON_ERROR),
            ['http_code' => 200],
        ));

        self::assertSame(DeployProbeVerdict::READY, $verdict->state);
        self::assertSame('Zomboid Control Panel', $verdict->applicationName);
        self::assertSame('running:healthy', $verdict->applicationState);
    }

    /** A failure body is shortened, because it is shown as text. */
    public function testKeepsAFailureDetailShortEnoughToRead(): void
    {
        $verdict = $this->probe(new MockResponse(
            str_repeat('y', 2000),
            ['http_code' => 500],
        ));

        self::assertNotNull($verdict->detail);
        self::assertLessThanOrEqual(500, mb_strlen($verdict->detail));
    }

    public function testFollowsTheDeploymentCoolifyNamed(): void
    {
        $body = json_encode([
            'deployments' => [[
                'message' => 'queued',
                'resource_uuid' => '7yjy9uoz032inopxjf2mzvcl',
                'deployment_uuid' => 'dep123456789',
            ]],
        ], \JSON_THROW_ON_ERROR);

        self::assertSame('dep123456789', \App\Panel\DeployTrigger::deploymentUuidIn($body));
    }

    public function testHasNoDeploymentToFollowWhenTheAnswerNamesNone(): void
    {
        self::assertNull(\App\Panel\DeployTrigger::deploymentUuidIn('{"message":"ok"}'));
        self::assertNull(\App\Panel\DeployTrigger::deploymentUuidIn('not json'));
    }

    public function testReadsTheStatusOfTheDeploymentItStarted(): void
    {
        $seen = [];

        $client = new MockHttpClient(static function (string $method, string $url) use (&$seen): MockResponse {
            $seen[] = $method.' '.$url;

            return new MockResponse('{"status":"in_progress"}', ['http_code' => 200]);
        });

        $status = (new DeployProbe($client, $this->settings(self::HOOK, 'tok'), new NullLogger()))
            ->statusOf('dep123456789');

        self::assertSame(DeploymentStatus::RUNNING, $status->state);
        self::assertFalse($status->isSettled());
        self::assertSame(
            ['GET https://coolify.example.com/api/v1/deployments/dep123456789'],
            $seen,
        );
    }

    public function testASettledDeploymentStopsTheWaiting(): void
    {
        $client = new MockHttpClient(new MockResponse('{"status":"finished"}', ['http_code' => 200]));

        $status = (new DeployProbe($client, $this->settings(self::HOOK, 'tok'), new NullLogger()))
            ->statusOf('dep123456789');

        self::assertTrue($status->isSettled());
        self::assertTrue($status->succeeded());
    }

    public function testAFailedDeploymentIsSettledButNotASuccess(): void
    {
        $client = new MockHttpClient(new MockResponse('{"status":"failed"}', ['http_code' => 200]));

        $status = (new DeployProbe($client, $this->settings(self::HOOK, 'tok'), new NullLogger()))
            ->statusOf('dep123456789');

        self::assertSame(DeploymentStatus::FAILED, $status->state);
        self::assertTrue($status->isSettled());
        self::assertFalse($status->succeeded());
    }

    /**
     * Coolify declares no set of status values, so a word nobody
     * planned for must be reported rather than read as progress.
     */
    public function testKeepsAStatusNobodyPlannedFor(): void
    {
        $status = DeploymentStatus::fromReported('somebody-renamed-this');

        self::assertSame(DeploymentStatus::UNKNOWN, $status->state);
        self::assertSame('somebody-renamed-this', $status->reported);
        self::assertFalse($status->succeeded());
        self::assertFalse($status->isSettled(), 'unknown is not settled; keep asking');
    }

    public function testTreatsQueuedAndInProgressAlike(): void
    {
        foreach (['queued', 'in_progress', 'running'] as $reported) {
            self::assertSame(
                DeploymentStatus::RUNNING,
                DeploymentStatus::fromReported($reported)->state,
                $reported.' is still going',
            );
        }
    }

    public function testADeletedDeploymentIsSettledRatherThanPolledForever(): void
    {
        $client = new MockHttpClient(new MockResponse('{"message":"Not found."}', ['http_code' => 404]));

        $status = (new DeployProbe($client, $this->settings(self::HOOK, 'tok'), new NullLogger()))
            ->statusOf('dep123456789');

        self::assertSame(DeploymentStatus::NOT_FOUND, $status->state);
        self::assertTrue($status->isSettled());
    }

    public function testRefusesADeploymentIdThatCouldChangeThePath(): void
    {
        $client = new MockHttpClient([]);

        $status = (new DeployProbe($client, $this->settings(self::HOOK, 'tok'), new NullLogger()))
            ->statusOf('../applications/other');

        self::assertSame(DeploymentStatus::NOT_FOUND, $status->state);
    }

    private function probe(MockResponse $response): DeployProbeVerdict
    {
        return (new DeployProbe(
            new MockHttpClient($response),
            $this->settings(self::HOOK, 'tok'),
            new NullLogger(),
        ))->probe();
    }

    private function settings(?string $hook, ?string $token): SettingsProvider
    {
        $stored = [];

        if ($hook !== null) {
            $stored[] = new AppSetting(AppSetting::DEPLOY_WEBHOOK_URL, $hook);
        }

        if ($token !== null) {
            $stored[] = new AppSetting(AppSetting::DEPLOY_WEBHOOK_TOKEN, $token);
        }

        return new SettingsProvider(
            new ProbeSettingRepository($stored),
            $this->createStub(EntityManagerInterface::class),
            [],
        );
    }
}

final class ProbeSettingRepository extends AppSettingRepository
{
    /** @param list<AppSetting> $stored */
    public function __construct(private readonly array $stored)
    {
    }

    /** @return array<string, string> */
    public function findAllAsMap(): array
    {
        $map = [];

        foreach ($this->stored as $setting) {
            $value = $setting->getValue();

            if ($value !== null && $value !== '') {
                $map[$setting->getName()] = $value;
            }
        }

        return $map;
    }
}
