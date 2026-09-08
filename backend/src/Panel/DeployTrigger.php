<?php

declare(strict_types=1);

namespace App\Panel;

use App\Entity\AppSetting;
use App\Settings\SettingsProvider;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Asks the hosting platform to pull the current image.
 *
 * The call comes from here rather than from the release pipeline for one
 * reason: a deploy hook is as good as a login, so the platform's own
 * address allow-list is worth keeping — and a CI runner has no fixed
 * address to put on it. This runs on the operator's own machine, which
 * is already on that list.
 */
final readonly class DeployTrigger
{
    private const TIMEOUT_SECONDS = 20;

    public function __construct(
        private HttpClientInterface $http,
        private SettingsProvider $settings,
        private LoggerInterface $logger,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->url() !== null;
    }

    /**
     * The intervals the interface offers, in minutes.
     *
     * Five is the floor on purpose: at one minute the panel would make
     * sixty GitHub calls an hour, which is exactly the unauthenticated
     * limit, and a rate-limited check answers "cannot tell" rather than
     * a version.
     */
    public const CHECK_INTERVALS = [5, 10, 15, 30, 60, 360, 720, 1440];

    /** What an installation that never chose gets. */
    public const DEFAULT_CHECK_MINUTES = 60;

    /** Whether the operator asked for a release to deploy itself. */
    public function isEnabled(): bool
    {
        return $this->settings->get(AppSetting::DEPLOY_ON_RELEASE) === '1' && $this->isConfigured();
    }

    /** Whether an open panel takes the new version by itself. */
    public function reloadsThePanel(): bool
    {
        return $this->settings->get(AppSetting::DEPLOY_RELOAD_PANEL) === '1';
    }

    /**
     * How often the operator wants the check to run.
     *
     * The scheduler fires at the shortest interval on offer and this
     * decides whether enough time has passed, so changing it needs no
     * restart -- and an unrecognised stored value is not coerced to the
     * default silently, it simply is not one of the choices.
     */
    public function checkIntervalMinutes(): int
    {
        $stored = $this->settings->get(AppSetting::DEPLOY_CHECK_MINUTES);
        $minutes = $stored === null ? 0 : (int) $stored;

        return \in_array($minutes, self::CHECK_INTERVALS, true) ? $minutes : self::DEFAULT_CHECK_MINUTES;
    }

    /**
     * Calls the hook and says what came back.
     *
     * A 2xx means the platform accepted the request and queued a
     * deployment. It does not mean the container is up: the panel's own
     * version line is what proves that, once it restarts.
     */
    public function fire(): DeployOutcome
    {
        $url = $this->url();

        if ($url === null) {
            return DeployOutcome::notConfigured();
        }

        $token = $this->settings->get(AppSetting::DEPLOY_WEBHOOK_TOKEN);
        $headers = ['Accept' => 'application/json'];

        if ($token !== null) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        try {
            // POST rather than GET: Coolify answers a GET with 405 and
            // "This endpoint has changed to a POST request."
            $response = $this->http->request('POST', $url, [
                'timeout' => self::TIMEOUT_SECONDS,
                'headers' => $headers,
            ]);

            $status = $response->getStatusCode();
            $body = mb_substr(trim($response->getContent(false)), 0, 500);
        } catch (\Throwable $exception) {
            $this->logger->warning('Deploy hook could not be reached: {reason}', [
                'reason' => $exception->getMessage(),
            ]);

            return DeployOutcome::unreachable($exception->getMessage());
        }

        if ($status >= 200 && $status < 300) {
            $this->logger->info('Deployment requested; the platform answered {status}.', [
                'status' => $status,
            ]);

            return DeployOutcome::queued($status, $body, self::deploymentUuidIn($body));
        }

        $this->logger->warning('The deploy hook refused: {status} {body}', [
            'status' => $status,
            'body' => $body,
        ]);

        return DeployOutcome::refused($status, $body);
    }

    /**
     * The uuid Coolify gives the deployment it just queued, so the
     * interface can follow that one rather than the newest it sees.
     */
    public static function deploymentUuidIn(string $body): ?string
    {
        $payload = json_decode($body, true);

        if (!\is_array($payload) || !\is_array($payload['deployments'] ?? null)) {
            return null;
        }

        $first = $payload['deployments'][0] ?? null;
        $uuid = \is_array($first) ? ($first['deployment_uuid'] ?? null) : null;

        return \is_string($uuid) && preg_match('/^[A-Za-z0-9_-]{6,64}$/', $uuid) === 1 ? $uuid : null;
    }

    private function url(): ?string
    {
        $url = $this->settings->get(AppSetting::DEPLOY_WEBHOOK_URL);

        if ($url === null) {
            return null;
        }

        $url = trim($url);

        // Only http(s), and only an absolute address: a stored value is
        // an operator's, but this makes a request on their behalf.
        return preg_match('#^https?://[^\s]+$#', $url) === 1 ? $url : null;
    }
}
