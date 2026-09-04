<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260904163732 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE player_snapshot (id UUID NOT NULL, username VARCHAR(100) NOT NULL, steam_id VARCHAR(20) DEFAULT NULL, online BOOLEAN DEFAULT true NOT NULL, x DOUBLE PRECISION NOT NULL, y DOUBLE PRECISION NOT NULL, z DOUBLE PRECISION NOT NULL, health DOUBLE PRECISION NOT NULL, infected BOOLEAN DEFAULT false NOT NULL, infection_level DOUBLE PRECISION DEFAULT 0 NOT NULL, hours_survived DOUBLE PRECISION DEFAULT 0 NOT NULL, access_level VARCHAR(30) DEFAULT NULL, skills JSON NOT NULL, traits JSON NOT NULL, last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, first_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, server_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_2A9A54D51844E6B7 ON player_snapshot (server_id)');
        $this->addSql('CREATE INDEX idx_server_online ON player_snapshot (server_id, online)');
        $this->addSql('CREATE UNIQUE INDEX uniq_server_username ON player_snapshot (server_id, username)');
        $this->addSql('ALTER TABLE player_snapshot ADD CONSTRAINT FK_2A9A54D51844E6B7 FOREIGN KEY (server_id) REFERENCES game_server (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE player_snapshot DROP CONSTRAINT FK_2A9A54D51844E6B7');
        $this->addSql('DROP TABLE player_snapshot');
    }
}
