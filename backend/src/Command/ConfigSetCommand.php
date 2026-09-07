<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\FtpConfig;
use App\Entity\GameServer;
use App\Repository\GameServerRepository;
use App\Server\Config\ConfigFileLocator;
use App\Server\Config\ConfigKind;
use App\Server\Config\ConfigReloader;
use App\Server\Config\ConfigWriteRefused;
use App\Server\Config\ConfigWriter;
use App\Server\Storage\StorageException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Changes one setting from the command line, with the same guards.
 *
 * The panel is the way an operator does this; this exists so the write
 * path can be exercised without a browser, and so a support case can be
 * fixed when the panel itself is what is broken.
 */
#[AsCommand(
    name: 'app:config:set',
    description: "Change one value in a server's settings file",
)]
final class ConfigSetCommand extends Command
{
    public function __construct(
        private readonly GameServerRepository $servers,
        private readonly ConfigFileLocator $locator,
        private readonly ConfigWriter $writer,
        private readonly ConfigReloader $reloader,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('kind', InputArgument::REQUIRED, 'sandbox or ini');
        $this->addArgument('key', InputArgument::REQUIRED, 'The option, as the file keys it');
        $this->addArgument('value', InputArgument::REQUIRED, 'The new value');
        $this->addOption('server', 's', InputOption::VALUE_REQUIRED, 'Server id or name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $kind = ConfigKind::tryFrom((string) $input->getArgument('kind'));

        if ($kind === null) {
            $io->error('the kind must be "sandbox" or "ini"');

            return Command::FAILURE;
        }

        $name = $input->getOption('server');
        $server = is_string($name)
            ? ($this->servers->find($name) ?? $this->servers->findOneBy(['name' => $name]))
            : ($this->servers->findBy([], ['name' => 'ASC'])[0] ?? null);

        if (!$server instanceof GameServer || $server->getFtpConfig() === null) {
            $io->error('no such server, or it has no transfer credentials');

            return Command::FAILURE;
        }

        $config = $server->getFtpConfig();
        $found = $this->locator->locate($config);
        $names = $kind === ConfigKind::Sandbox ? $found['sandbox'] : $found['ini'];

        if ($names === []) {
            $io->error(sprintf('this server has no %s file', $kind->value));

            return Command::FAILURE;
        }

        $path = rtrim((string) $found['directory'], '/').'/'.$names[0];
        $key = (string) $input->getArgument('key');

        try {
            $result = $this->writer->apply($config, $path, $kind, [
                $key => $this->coerce((string) $input->getArgument('value')),
            ]);
        } catch (ConfigWriteRefused $refused) {
            $io->error($refused->getMessage());

            return Command::FAILURE;
        } catch (StorageException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->definitionList(
            ['file' => $path],
            ['written' => implode(', ', $result['written']) ?: '(no change needed)'],
            ['backup' => $result['backup']['state'].($result['backup']['path'] === null ? '' : ' → '.$result['backup']['path'])],
            ['verified' => $result['verified'] ? 'yes' : 'NO'],
        );

        if (!$result['verified']) {
            $io->error(sprintf(
                'read-back did not match for: %s; backup %s',
                implode(', ', $result['mismatched']),
                $result['restored'] ? 'restored' : 'COULD NOT BE RESTORED',
            ));

            return Command::FAILURE;
        }

        $applied = $this->reloader->apply($server, $kind, [$key => $this->coerce((string) $input->getArgument('value'))]);

        $io->writeln(sprintf('  apply: <info>%s</info>', $applied->value));

        if ($applied->needsRestart()) {
            $io->warning('written, but the game server has to restart before it reads this');

            return Command::SUCCESS;
        }

        $io->success('written, read back and live on the running server');

        return Command::SUCCESS;
    }

    /** The file's own types: `true`, `12`, `0.5` or text. */
    private function coerce(string $raw): bool|float|int|string
    {
        return match (true) {
            $raw === 'true' => true,
            $raw === 'false' => false,
            preg_match('/^-?\d+$/', $raw) === 1 => (int) $raw,
            is_numeric($raw) => (float) $raw,
            default => $raw,
        };
    }
}
