<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The staff's own memory of a player: a note and a few tags.
 *
 * One row per player per server, updated in place. Everything else the
 * dossier shows is read from the game or from the moderation log; this is
 * the part that would otherwise live in somebody's head and be lost at
 * the next handover.
 */
final class Version20260906193959 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds player notes and tags for the dossier';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE player_note (id UUID NOT NULL, username VARCHAR(100) NOT NULL, note TEXT DEFAULT NULL, tags JSON DEFAULT \'[]\' NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, server_id UUID NOT NULL, updated_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_6DA7AA271844E6B7 ON player_note (server_id)');
        $this->addSql('CREATE INDEX IDX_6DA7AA27896DBBDE ON player_note (updated_by_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_note_server_username ON player_note (server_id, username)');
        $this->addSql('ALTER TABLE player_note ADD CONSTRAINT FK_6DA7AA271844E6B7 FOREIGN KEY (server_id) REFERENCES game_server (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE player_note ADD CONSTRAINT FK_6DA7AA27896DBBDE FOREIGN KEY (updated_by_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
        // The generator also proposed dropping three messenger_messages
        // indexes, which belong to the transport rather than to this
        // change and are what keep the queue fast. Left in place.
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE player_note DROP CONSTRAINT FK_6DA7AA271844E6B7');
        $this->addSql('ALTER TABLE player_note DROP CONSTRAINT FK_6DA7AA27896DBBDE');
        $this->addSql('DROP TABLE player_note');
    }
}
