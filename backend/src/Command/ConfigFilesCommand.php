<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\GameServer;
use App\Repository\GameServerRepository;
use App\Server\Config\ConfigFileLocator;
use App\Server\Config\ConfigReader;
use App\Server\Storage\StorageException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reports which settings files a server has, and what they hold.
 *
 * The names depend on the server rather than on the game, so this is how
 * an operator finds out what is actually there — and how a support case
 * gets answered without opening an FTP client.
 */
#[AsCommand(
    name: 'app:config:files',
    description: "Find a server's settings files and report what they hold",
)]
final class ConfigFilesCommand extends Command
{
    public function __construct(
        private readonly GameServerRepository $servers,
        private readonly ConfigFileLocator $locator,
        private readonly ConfigReader $reader,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('server', InputArgument::OPTIONAL, 'Server id or name; omit for the first');
        $this->addOption('read', 'r', InputOption::VALUE_REQUIRED, 'Also read one: sandbox or ini');
        $this->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'How many values to list', '15');
        $this->addOption('unknown', 'u', InputOption::VALUE_NONE, 'List only what the schema does not know');
    }

    private function resolve(mixed $needle): ?GameServer
    {
        if (!is_string($needle) || $needle === '') {
            return $this->servers->findBy([], ['name' => 'ASC'])[0] ?? null;
        }

        return $this->servers->find($needle) ?? $this->servers->findOneBy(['name' => $needle]);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $server = $this->resolve($input->getArgument('server'));

        if (!$server instanceof GameServer) {
            $io->error('no such server');

            return Command::FAILURE;
        }

        $config = $server->getFtpConfig();

        if ($config === null) {
            $io->error(sprintf('%s has no transfer credentials', $server->getName()));

            return Command::FAILURE;
        }

        $found = $this->locator->locate($config);

        if ($found['directory'] === null) {
            $io->warning(sprintf(
                'no settings files found; looked in %s',
                implode(', ', $found['searched']),
            ));

            if ($found['error'] !== null) {
                $io->error($found['error']);
            }

            return Command::FAILURE;
        }

        $io->definitionList(
            ['server' => $server->getName()],
            ['directory' => $found['directory']],
            ['ini' => implode(', ', $found['ini']) ?: '(none)'],
            ['sandbox' => implode(', ', $found['sandbox']) ?: '(none)'],
            ['spawn regions' => implode(', ', $found['spawnRegions']) ?: '(none)'],
            ['spawn points' => implode(', ', $found['spawnPoints']) ?: '(none)'],
        );

        $kind = $input->getOption('read');

        if (!is_string($kind)) {
            return Command::SUCCESS;
        }

        return $this->read($io, $config, $found, $kind, $input);
    }

    /**
     * @param array<string, mixed> $found
     */
    private function read(
        SymfonyStyle $io,
        \App\Entity\FtpConfig $config,
        array $found,
        string $kind,
        InputInterface $input,
    ): int {
        if (!in_array($kind, ['sandbox', 'ini'], true)) {
            $io->error('--read takes "sandbox" or "ini"');

            return Command::FAILURE;
        }

        $names = $kind === 'sandbox' ? $found['sandbox'] : $found['ini'];

        if ($names === []) {
            $io->error(sprintf('this server has no %s file', $kind));

            return Command::FAILURE;
        }

        $path = rtrim((string) $found['directory'], '/').'/'.$names[0];

        try {
            $read = $kind === 'sandbox'
                ? $this->reader->sandbox($config, $path)
                : $this->reader->ini($config, $path);
        } catch (StorageException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $values = $read['values'];

        if ($input->getOption('unknown') === true) {
            $values = array_values(array_filter(
                $values,
                static fn (array $value): bool => $value['known'] === false,
            ));
        }

        $io->writeln(sprintf(
            '%s: %d values, %d this build does not know',
            $path,
            count($read['values']),
            $read['unknown'],
        ));

        $limit = max(1, (int) $input->getOption('limit'));

        $io->table(
            ['key', 'value', 'type', 'known', 'range', 'label'],
            array_map(
                static fn (array $value): array => [
                    $value['key'],
                    is_bool($value['value']) ? ($value['value'] ? 'true' : 'false') : (string) $value['value'],
                    (string) $value['type'],
                    $value['known'] ? 'yes' : 'NO',
                    $value['min'] === null ? '' : sprintf('%s..%s', $value['min'], $value['max']),
                    (string) ($value['labels']['EN'] ?? ''),
                ],
                array_slice($values, 0, $limit),
            ),
        );

        return Command::SUCCESS;
    }
}
