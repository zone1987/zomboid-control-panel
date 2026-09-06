<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260906043000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove retired map-render jobs and stored S3 configuration';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("DELETE FROM app_setting WHERE name IN ('s3.endpoint', 's3.region', 's3.bucket', 's3.access_key', 's3.secret_key')");
        $this->addSql(<<<'SQL'
            DELETE FROM messenger_messages
            WHERE strpos(body, 'App\Message\RenderWorld') > 0
               OR strpos(body, 'App\\Message\\RenderWorld') > 0
               OR headers::jsonb ->> 'type' = 'App\Message\RenderWorld'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Deleted obsolete credentials and render jobs cannot be restored.');
    }
}
