<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The queue behind asynchronous mail. Written by hand because the table
 * belongs to Messenger rather than the ORM, so diff never sees it, and
 * the transport runs with auto_setup=0 in production.
 */
final class Version20260904180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the messenger_messages queue table';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration targets PostgreSQL.',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS messenger_messages (
                id BIGSERIAL NOT NULL,
                body TEXT NOT NULL,
                headers TEXT NOT NULL,
                queue_name VARCHAR(190) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                available_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);

        $this->addSql('CREATE INDEX IF NOT EXISTS idx_messenger_queue_name ON messenger_messages (queue_name)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_messenger_available_at ON messenger_messages (available_at)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_messenger_delivered_at ON messenger_messages (delivered_at)');

        // Workers wait on this rather than polling; without it a queued
        // mail sits until the next poll interval.
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION notify_messenger_messages() RETURNS TRIGGER AS $$
                BEGIN
                    PERFORM pg_notify('messenger_messages', NEW.queue_name::text);
                    RETURN NEW;
                END;
            $$ LANGUAGE plpgsql;
        SQL);

        $this->addSql('DROP TRIGGER IF EXISTS notify_trigger ON messenger_messages');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER notify_trigger
                AFTER INSERT OR UPDATE ON messenger_messages
                FOR EACH ROW EXECUTE PROCEDURE notify_messenger_messages();
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS notify_trigger ON messenger_messages');
        $this->addSql('DROP FUNCTION IF EXISTS notify_messenger_messages()');
        $this->addSql('DROP TABLE IF EXISTS messenger_messages');
    }
}
