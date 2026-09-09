<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Records which game build a server runs, for filtering workshop mods.
 *
 * Nullable because nobody has said yet for any existing server, and an
 * unknown build must stay distinguishable from a known one: it filters
 * nothing rather than guessing and hiding mods that are fine.
 */
final class Version20260909073007 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the game build a server runs, used to filter workshop mods';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_server ADD game_build VARCHAR(8) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_server DROP game_build');
    }
}
