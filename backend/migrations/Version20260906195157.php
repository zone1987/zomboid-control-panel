<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * What was asked for, and whether it worked.
 *
 * The history could say "rain" but never "rain at 70": the inputs were
 * dropped on the way in. And a list that cannot tell a refusal from a
 * success reads as though everything worked.
 *
 * Both nullable, and null is honest for every existing row: nobody
 * recorded those things, so claiming false would be inventing history.
 */
final class Version20260906195157 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Records an action own inputs and whether the server refused it';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE moderation_action ADD inputs JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE moderation_action ADD failed BOOLEAN DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE moderation_action DROP inputs');
        $this->addSql('ALTER TABLE moderation_action DROP failed');
    }
}
