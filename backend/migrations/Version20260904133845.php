<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260904133845 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE app_user (id UUID NOT NULL, email VARCHAR(180) NOT NULL, display_name VARCHAR(100) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) DEFAULT NULL, locale VARCHAR(5) DEFAULT \'de\' NOT NULL, active BOOLEAN DEFAULT true NOT NULL, totp_secret TEXT DEFAULT NULL, backup_codes JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_login_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_88BDF3E9E7927C74 ON app_user (email)');
        $this->addSql('CREATE TABLE ftp_config (id UUID NOT NULL, protocol VARCHAR(10) NOT NULL, host VARCHAR(191) NOT NULL, port INT NOT NULL, username VARCHAR(191) NOT NULL, password TEXT DEFAULT NULL, private_key TEXT DEFAULT NULL, base_path VARCHAR(500) DEFAULT \'/\' NOT NULL, lua_server_path VARCHAR(500) DEFAULT NULL, log_path VARCHAR(500) DEFAULT NULL, last_verified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, server_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8EB908891844E6B7 ON ftp_config (server_id)');
        $this->addSql('CREATE TABLE game_server (id UUID NOT NULL, name VARCHAR(100) NOT NULL, description TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE invitation (id UUID NOT NULL, email VARCHAR(180) NOT NULL, token_hash VARCHAR(64) NOT NULL, roles JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, accepted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, invited_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_F11D61A2B3BC57DA ON invitation (token_hash)');
        $this->addSql('CREATE INDEX IDX_F11D61A2A7B4A7E3 ON invitation (invited_by_id)');
        $this->addSql('CREATE TABLE oauth_identity (id UUID NOT NULL, provider VARCHAR(20) NOT NULL, provider_user_id VARCHAR(191) NOT NULL, provider_label VARCHAR(191) DEFAULT NULL, linked_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_4AE96AD8A76ED395 ON oauth_identity (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_provider_user ON oauth_identity (provider, provider_user_id)');
        $this->addSql('CREATE TABLE rcon_config (id UUID NOT NULL, host VARCHAR(191) NOT NULL, port INT NOT NULL, password TEXT NOT NULL, last_verified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, server_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1770ED01844E6B7 ON rcon_config (server_id)');
        $this->addSql('CREATE TABLE webauthn_credential (id UUID NOT NULL, name VARCHAR(100) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, public_key_credential_id TEXT NOT NULL, type VARCHAR(100) NOT NULL, transports JSON NOT NULL, attestation_type VARCHAR(100) NOT NULL, trust_path JSON NOT NULL, aaguid TEXT NOT NULL, credential_public_key TEXT NOT NULL, user_handle VARCHAR(191) NOT NULL, counter INT NOT NULL, other_ui JSON DEFAULT NULL, backup_eligible BOOLEAN DEFAULT NULL, backup_status BOOLEAN DEFAULT NULL, uv_initialized BOOLEAN DEFAULT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_850123F972A8BD77 ON webauthn_credential (public_key_credential_id)');
        $this->addSql('CREATE INDEX IDX_850123F9A76ED395 ON webauthn_credential (user_id)');
        $this->addSql('ALTER TABLE ftp_config ADD CONSTRAINT FK_8EB908891844E6B7 FOREIGN KEY (server_id) REFERENCES game_server (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE invitation ADD CONSTRAINT FK_F11D61A2A7B4A7E3 FOREIGN KEY (invited_by_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE oauth_identity ADD CONSTRAINT FK_4AE96AD8A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE rcon_config ADD CONSTRAINT FK_1770ED01844E6B7 FOREIGN KEY (server_id) REFERENCES game_server (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE webauthn_credential ADD CONSTRAINT FK_850123F9A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ftp_config DROP CONSTRAINT FK_8EB908891844E6B7');
        $this->addSql('ALTER TABLE invitation DROP CONSTRAINT FK_F11D61A2A7B4A7E3');
        $this->addSql('ALTER TABLE oauth_identity DROP CONSTRAINT FK_4AE96AD8A76ED395');
        $this->addSql('ALTER TABLE rcon_config DROP CONSTRAINT FK_1770ED01844E6B7');
        $this->addSql('ALTER TABLE webauthn_credential DROP CONSTRAINT FK_850123F9A76ED395');
        $this->addSql('DROP TABLE app_user');
        $this->addSql('DROP TABLE ftp_config');
        $this->addSql('DROP TABLE game_server');
        $this->addSql('DROP TABLE invitation');
        $this->addSql('DROP TABLE oauth_identity');
        $this->addSql('DROP TABLE rcon_config');
        $this->addSql('DROP TABLE webauthn_credential');
    }
}
