<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260904163905 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE moderation_action (id UUID NOT NULL, action VARCHAR(20) NOT NULL, username VARCHAR(100) NOT NULL, reason TEXT DEFAULT NULL, reply TEXT DEFAULT NULL, performed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, server_id UUID NOT NULL, performed_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_B05D81281844E6B7 ON moderation_action (server_id)');
        $this->addSql('CREATE INDEX IDX_B05D81282E65C292 ON moderation_action (performed_by_id)');
        $this->addSql('CREATE INDEX idx_server_username ON moderation_action (server_id, username)');
        $this->addSql('ALTER TABLE moderation_action ADD CONSTRAINT FK_B05D81281844E6B7 FOREIGN KEY (server_id) REFERENCES game_server (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE moderation_action ADD CONSTRAINT FK_B05D81282E65C292 FOREIGN KEY (performed_by_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE moderation_action DROP CONSTRAINT FK_B05D81281844E6B7');
        $this->addSql('ALTER TABLE moderation_action DROP CONSTRAINT FK_B05D81282E65C292');
        $this->addSql('DROP TABLE moderation_action');
    }
}
