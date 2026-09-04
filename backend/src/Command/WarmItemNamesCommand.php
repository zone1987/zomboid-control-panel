<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\GameServer;
use App\Repository\GameServerRepository;
use App\Server\Items\ItemCatalogue;
use App\Server\Items\ItemTranslations;
use App\Settings\SupportedLanguages;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Fetches the item catalogue and its names in every language the panel
 * speaks, so the first person to open the page waits for none of it.
 */
#[AsCommand(name: 'app:items:warm', description: 'Load the item catalogue and its translations into the cache')]
final class WarmItemNamesCommand extends Command
{
    public function __construct(
        private readonly GameServerRepository $servers,
        private readonly ItemCatalogue $catalogue,
        private readonly ItemTranslations $translations,
        private readonly SupportedLanguages $languages,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('server', InputArgument::OPTIONAL, 'Server id or name; omit for all');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $needle = $input->getArgument('server');

        $servers = $needle === null
            ? $this->servers->findBy([], ['name' => 'ASC'])
            : array_filter([$this->resolve($needle)]);

        if ($servers === []) {
            $io->error('No matching server.');

            return Command::FAILURE;
        }

        foreach ($servers as $server) {
            $io->section($server->getName());

            $catalogue = $this->catalogue->forServer($server);

            if (!$catalogue['available']) {
                $io->warning('No catalogue yet. The bridge writes it when the server starts.');

                continue;
            }

            $io->writeln(sprintf('%d items.', \count($catalogue['items'])));

            foreach ($this->languages->all() as $language) {
                $names = $this->translations->forLanguage($server, $language);

                $io->writeln(sprintf(
                    '  %s: %s',
                    $language,
                    $names === [] ? 'not installed on the server' : \count($names).' names',
                ));
            }
        }

        return Command::SUCCESS;
    }

    private function resolve(string $needle): ?GameServer
    {
        $byId = Uuid::isValid($needle) ? $this->servers->find($needle) : null;

        return $byId ?? $this->servers->findOneBy(['name' => $needle]);
    }
}
