<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The three tables the Discord integration needs.
 *
 * `discord_config` is one per server: which guild, which chat channel,
 * how much chat may leave the game. `discord_notification` is one per
 * server and event type, so each kind of event has its own switch,
 * channel and wording. `discord_command_right` is one per server and
 * subcommand, holding the Discord roles allowed to run it.
 *
 * All three cascade on the server, because none of them means anything
 * without it.
 */
final class Version20260907122103 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Discord: per-server guild config, per-event notifications, per-command rights';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE discord_command_right (id UUID NOT NULL, command VARCHAR(64) NOT NULL, role_ids JSON NOT NULL, server_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_4DD34DB21844E6B7 ON discord_command_right (server_id)');
        $this->addSql('CREATE UNIQUE INDEX discord_command_right_unique ON discord_command_right (server_id, command)');
        $this->addSql('CREATE TABLE discord_config (id UUID NOT NULL, guild_id VARCHAR(32) NOT NULL, chat_channel_id VARCHAR(32) DEFAULT NULL, chat_scope VARCHAR(16) NOT NULL, relay_into_game BOOLEAN NOT NULL, commands_enabled BOOLEAN NOT NULL, server_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4D8307101844E6B7 ON discord_config (server_id)');
        $this->addSql('CREATE TABLE discord_notification (id UUID NOT NULL, event_type VARCHAR(64) NOT NULL, enabled BOOLEAN NOT NULL, channel_id VARCHAR(32) DEFAULT NULL, template TEXT DEFAULT NULL, server_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_BE1911DB1844E6B7 ON discord_notification (server_id)');
        $this->addSql('CREATE UNIQUE INDEX discord_notification_unique ON discord_notification (server_id, event_type)');
        $this->addSql('ALTER TABLE discord_command_right ADD CONSTRAINT FK_4DD34DB21844E6B7 FOREIGN KEY (server_id) REFERENCES game_server (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE discord_config ADD CONSTRAINT FK_4D8307101844E6B7 FOREIGN KEY (server_id) REFERENCES game_server (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE discord_notification ADD CONSTRAINT FK_BE1911DB1844E6B7 FOREIGN KEY (server_id) REFERENCES game_server (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE discord_command_right DROP CONSTRAINT FK_4DD34DB21844E6B7');
        $this->addSql('ALTER TABLE discord_config DROP CONSTRAINT FK_4D8307101844E6B7');
        $this->addSql('ALTER TABLE discord_notification DROP CONSTRAINT FK_BE1911DB1844E6B7');
        $this->addSql('DROP TABLE discord_command_right');
        $this->addSql('DROP TABLE discord_config');
        $this->addSql('DROP TABLE discord_notification');
    }
}
