<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Grants the new vehicles.view permission to roles that already see the
 * world.
 *
 * Vehicles on the map used to ride along with players.view. Splitting
 * them out would silently take the layer away from every existing
 * administrator, so the roles that had the old permission get the new
 * one too. A role that never saw players does not gain anything.
 */
final class Version20260906060000 extends AbstractMigration
{
    private const PERMISSION = 'vehicles.view';

    public function getDescription(): string
    {
        return 'Give roles that may see players the new vehicles.view permission';
    }

    public function up(Schema $schema): void
    {
        foreach ($this->rolesToChange() as $role) {
            /** @var list<string> $permissions */
            $permissions = json_decode((string) $role['permissions'], true, flags: \JSON_THROW_ON_ERROR);

            if (\in_array(self::PERMISSION, $permissions, true)) {
                continue;
            }

            $permissions[] = self::PERMISSION;

            $this->connection->update(
                'app_role',
                ['permissions' => json_encode(array_values($permissions), \JSON_THROW_ON_ERROR)],
                ['id' => $role['id']],
            );
        }
    }

    public function down(Schema $schema): void
    {
        foreach ($this->connection->fetchAllAssociative('SELECT id, permissions FROM app_role') as $role) {
            /** @var list<string> $permissions */
            $permissions = json_decode((string) $role['permissions'], true, flags: \JSON_THROW_ON_ERROR);
            $without = array_values(array_filter(
                $permissions,
                static fn (string $permission): bool => $permission !== self::PERMISSION,
            ));

            if (\count($without) === \count($permissions)) {
                continue;
            }

            $this->connection->update(
                'app_role',
                ['permissions' => json_encode($without, \JSON_THROW_ON_ERROR)],
                ['id' => $role['id']],
            );
        }
    }

    /**
     * Read rather than matched in SQL: the column is JSON and its
     * dialect differs between databases, while this runs on a handful
     * of rows.
     *
     * @return list<array{id: mixed, permissions: mixed}>
     */
    private function rolesToChange(): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT id, permissions FROM app_role');

        return array_values(array_filter($rows, static function (array $role): bool {
            $permissions = json_decode((string) $role['permissions'], true);

            return \is_array($permissions) && \in_array('players.view', $permissions, true);
        }));
    }
}
