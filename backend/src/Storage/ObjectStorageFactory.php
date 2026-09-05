<?php

declare(strict_types=1);

namespace App\Storage;

use App\Entity\AppSetting;
use App\Settings\SettingsProvider;
use AsyncAws\S3\S3Client;
use League\Flysystem\AsyncAwsS3\AsyncAwsS3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpClient\CurlHttpClient;

/**
 * The object store an operator configures in the interface.
 *
 * Rendered map tiles run to hundreds of megabytes and must survive a
 * redeploy, so they belong in a bucket rather than in the container.
 * Every provider that speaks S3 works; the endpoint is asked for
 * because Hetzner, Backblaze and MinIO are not AWS.
 */
final class ObjectStorageFactory implements ObjectStorageInterface
{
    /**
     * Transfers allowed to the store at once.
     *
     * AsyncAws builds its own client when handed none, and Symfony's
     * default caps a host at six. Raising it turned out not to be the
     * constraint: measured against Hetzner, 4, 8 and 16 connections all
     * hold ~15 objects a second, and 32 or more drop to ~9 as the
     * transfers compete. The line saturates at about 3.5 MB/s well
     * before the connection count matters.
     *
     * Sixteen leaves room for a faster line without the falling-off
     * seen above it.
     */
    private const CONNECTIONS = 16;

    private ?S3Client $client = null;

    /** @var array<string, string|null> */
    private array $settingsWhenBuilt = [];

    public function __construct(private readonly SettingsProvider $settings)
    {
    }

    public function isConfigured(): bool
    {
        foreach (self::REQUIRED as $name) {
            if (!$this->settings->isConfigured($name)) {
                return false;
            }
        }

        return true;
    }

    /** Every field the client cannot be built without. */
    private const REQUIRED = [
        AppSetting::S3_ENDPOINT,
        AppSetting::S3_REGION,
        AppSetting::S3_BUCKET,
        AppSetting::S3_ACCESS_KEY,
        AppSetting::S3_SECRET_KEY,
    ];

    /** @return list<string> the settings still missing */
    public function missing(): array
    {
        return array_values(array_filter(
            self::REQUIRED,
            fn (string $name): bool => !$this->settings->isConfigured($name),
        ));
    }

    /**
     * Accepts a host with or without a scheme.
     *
     * Providers print the endpoint both ways -- Hetzner's console shows
     * a bare host, its documentation an https URL -- and AsyncAws
     * refuses the bare one with "the endpoint is invalid", which says
     * nothing about what to change.
     */
    public static function normaliseEndpoint(string $endpoint): string
    {
        $endpoint = trim(rtrim(trim($endpoint), '/'));

        if ($endpoint === '' || preg_match('#^https?://#i', $endpoint) === 1) {
            return $endpoint;
        }

        return 'https://'.$endpoint;
    }

    public function bucket(): ?string
    {
        return $this->settings->get(AppSetting::S3_BUCKET);
    }

    /**
     * @throws ObjectStorageNotConfigured
     */
    public function create(): FilesystemOperator
    {
        return new Filesystem(new AsyncAwsS3Adapter($this->client(), (string) $this->bucket()));
    }

    public function client(): S3Client
    {
        if (!$this->isConfigured()) {
            throw new ObjectStorageNotConfigured();
        }

        $configuration = [
            'endpoint' => self::normaliseEndpoint((string) $this->settings->get(AppSetting::S3_ENDPOINT)),
            'region' => (string) $this->settings->get(AppSetting::S3_REGION),
            'accessKeyId' => (string) $this->settings->get(AppSetting::S3_ACCESS_KEY),
            'accessKeySecret' => (string) $this->settings->get(AppSetting::S3_SECRET_KEY),
            // Hetzner, Backblaze and MinIO address a bucket as a path
            // segment; only AWS puts it in the hostname.
            'pathStyleEndpoint' => true,
        ];

        // Kept between calls so the connection pool survives: a render
        // asks for a client every batch, and a fresh one pays for a new
        // set of TLS handshakes each time.
        if ($this->client !== null && $this->settingsWhenBuilt === $configuration) {
            return $this->client;
        }

        $this->settingsWhenBuilt = $configuration;

        return $this->client = new S3Client(
            $configuration,
            null,
            new CurlHttpClient([], self::CONNECTIONS),
        );
    }
}
