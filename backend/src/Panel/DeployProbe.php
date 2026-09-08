<?php

declare(strict_types=1);

namespace App\Panel;

use App\Entity\AppSetting;
use App\Settings\SettingsProvider;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Asks the hosting platform whether a deployment would work, without
 * starting one.
 *
 * `GET /applications/{uuid}` reads and changes nothing, so the answer
 * separates an unreachable host from a rejected token from a uuid that
 * names no application -- each of which needs a different fix.
 */
final readonly class DeployProbe
{
    private const TIMEOUT_SECONDS = 15;

    public function __construct(
        private HttpClientInterface $http,
        private SettingsProvider $settings,
        private LoggerInterface $logger,
    ) {
    }

    public function probe(): DeployProbeVerdict
    {
        $webhook = $this->settings->get(AppSetting::DEPLOY_WEBHOOK_URL);

        if ($webhook === null || trim($webhook) === '') {
            return DeployProbeVerdict::notConfigured();
        }

        $target = self::readUrlFrom(trim($webhook));

        if ($target === null) {
            return DeployProbeVerdict::noUuid();
        }

        $headers = ['Accept' => 'application/json'];
        $token = $this->settings->get(AppSetting::DEPLOY_WEBHOOK_TOKEN);

        if ($token !== null) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        try {
            $response = $this->http->request('GET', $target, [
                'timeout' => self::TIMEOUT_SECONDS,
                'headers' => $headers,
            ]);

            $status = $response->getStatusCode();
            // Not truncated here: the success body is parsed for the
            // application's name, and cutting it first makes the JSON
            // unparseable. Failure detail is shortened below instead.
            $body = trim($response->getContent(false));
        } catch (\Throwable $exception) {
            $this->logger->info('Deploy probe could not reach the platform: {reason}', [
                'reason' => $exception->getMessage(),
            ]);

            return DeployProbeVerdict::unreachable($exception->getMessage());
        }

        return $this->verdictFor($status, $body);
    }

    /**
     * How far a deployment has got.
     *
     * The panel restarts partway through its own deployment, so the
     * browser asks for this rather than the backend watching itself.
     */
    public function statusOf(string $deploymentUuid): DeploymentStatus
    {
        $webhook = $this->settings->get(AppSetting::DEPLOY_WEBHOOK_URL);

        if ($webhook === null || preg_match('/^[A-Za-z0-9_-]{6,64}$/', $deploymentUuid) !== 1) {
            return DeploymentStatus::notFound();
        }

        $base = self::baseOf(trim($webhook));

        if ($base === null) {
            return DeploymentStatus::notFound();
        }

        $headers = ['Accept' => 'application/json'];
        $token = $this->settings->get(AppSetting::DEPLOY_WEBHOOK_TOKEN);

        if ($token !== null) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        try {
            $response = $this->http->request('GET', $base.'/api/v1/deployments/'.$deploymentUuid, [
                'timeout' => self::TIMEOUT_SECONDS,
                'headers' => $headers,
            ]);

            $status = $response->getStatusCode();
            $body = mb_substr(trim($response->getContent(false)), 0, 500);
        } catch (\Throwable $exception) {
            return DeploymentStatus::unreachable($exception->getMessage());
        }

        if ($status === 404) {
            return DeploymentStatus::notFound();
        }

        if ($status < 200 || $status >= 300) {
            return DeploymentStatus::unreachable(sprintf('%d %s', $status, $body));
        }

        $payload = json_decode($body, true);
        $reported = \is_array($payload) ? ($payload['status'] ?? null) : null;

        return \is_string($reported) && $reported !== ''
            ? DeploymentStatus::fromReported($reported)
            : DeploymentStatus::unreachable('the platform reported no status');
    }

    /**
     * The read address for the application the webhook names.
     *
     * The uuid is already in the hook the operator pasted, so it is not
     * asked for twice.
     */
    public static function readUrlFrom(string $webhook): ?string
    {
        if (preg_match('#^https?://[^\s]+$#', $webhook) !== 1) {
            return null;
        }

        $parts = parse_url($webhook);

        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'], $parts['query'])) {
            return null;
        }

        parse_str($parts['query'], $query);
        $uuid = $query['uuid'] ?? null;

        // A comma-separated list is valid for the hook; the probe reads
        // the first, which is the one a single-application panel has.
        if (\is_string($uuid) && str_contains($uuid, ',')) {
            $uuid = explode(',', $uuid)[0];
        }

        if (!\is_string($uuid) || preg_match('/^[A-Za-z0-9_-]{6,64}$/', $uuid) !== 1) {
            return null;
        }

        $base = self::baseOf($webhook);

        return $base === null ? null : $base.'/api/v1/applications/'.$uuid;
    }

    /** Scheme, host and port of the operator's own Coolify instance. */
    public static function baseOf(string $webhook): ?string
    {
        if (preg_match('#^https?://[^\s]+$#', $webhook) !== 1) {
            return null;
        }

        $parts = parse_url($webhook);

        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $base = $parts['scheme'].'://'.$parts['host'];

        return isset($parts['port']) ? $base.':'.$parts['port'] : $base;
    }

    private function verdictFor(int $status, string $body): DeployProbeVerdict
    {
        $detail = $body === '' ? null : mb_substr($body, 0, 500);

        if ($status >= 200 && $status < 300) {
            return DeployProbeVerdict::ready($this->nameIn($body), $this->runStateIn($body));
        }

        return match (true) {
            $status === 401 => DeployProbeVerdict::tokenRejected($status, $detail),
            // Coolify's `deploy` and `read` are separate permissions, so
            // a token that may deploy can be refused here and still work.
            $status === 403 => DeployProbeVerdict::noReadPermission($status, $detail),
            $status === 404 => DeployProbeVerdict::notFound($status, $detail),
            default => DeployProbeVerdict::refused($status, $detail),
        };
    }

    /** Whether the application is running, as the platform sees it. */
    private function runStateIn(string $body): ?string
    {
        $payload = json_decode($body, true);
        $state = \is_array($payload) ? ($payload['status'] ?? null) : null;

        return \is_string($state) && $state !== '' ? mb_substr($state, 0, 60) : null;
    }

    private function nameIn(string $body): ?string
    {
        $payload = json_decode($body, true);

        if (!\is_array($payload)) {
            return null;
        }

        $name = $payload['name'] ?? null;

        return \is_string($name) && $name !== '' ? mb_substr($name, 0, 100) : null;
    }
}
