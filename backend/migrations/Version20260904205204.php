<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260904205204 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Roles carrying permissions, assignable to users';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_role (id UUID NOT NULL, name VARCHAR(60) NOT NULL, label VARCHAR(100) NOT NULL, permissions JSON NOT NULL, built_in BOOLEAN DEFAULT false NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5247AFCA5E237E06 ON app_role (name)');
        $this->addSql('CREATE TABLE app_user_role (user_id UUID NOT NULL, role_id UUID NOT NULL, PRIMARY KEY (user_id, role_id))');
        $this->addSql('CREATE INDEX IDX_85879996A76ED395 ON app_user_role (user_id)');
        $this->addSql('CREATE INDEX IDX_85879996D60322AC ON app_user_role (role_id)');
        $this->addSql('ALTER TABLE app_user_role ADD CONSTRAINT FK_85879996A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE app_user_role ADD CONSTRAINT FK_85879996D60322AC FOREIGN KEY (role_id) REFERENCES app_role (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user_role DROP CONSTRAINT FK_85879996A76ED395');
        $this->addSql('ALTER TABLE app_user_role DROP CONSTRAINT FK_85879996D60322AC');
        $this->addSql('DROP TABLE app_role');
        $this->addSql('DROP TABLE app_user_role');
    }
}
