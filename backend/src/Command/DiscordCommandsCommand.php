<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\AppSetting;
use App\Entity\GameServer;
use App\Repository\GameServerRepository;
use App\Server\Discord\CommandCatalogue;
use App\Server\Discord\DiscordClientInterface;
use App\Server\Discord\DiscordException;
use App\Settings\SettingsProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * What Discord actually holds for a guild, and optionally re-sends it.
 *
 * Registering answers "accepted", which is not the same as "present" —
 * and an operator who cannot find a command in Discord cannot tell a
 * failed registration from a client showing a cached list. Asking
 * Discord settles it in one command.
 */
#[AsCommand(
    name: 'app:discord:commands',
    description: 'List the slash commands Discord holds for a server',
)]
final class DiscordCommandsCommand extends Command
{
    public function __construct(
        private readonly GameServerRepository $servers,
        private readonly DiscordClientInterface $discord,
        private readonly SettingsProvider $settings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('server', InputArgument::OPTIONAL, 'Server id or name; omit for the first');
        $this->addOption('register', 'r', InputOption::VALUE_NONE, 'Send the catalogue again first');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $needle = $input->getArgument('server');

        $server = is_string($needle) && $needle !== ''
            ? ($this->servers->find($needle) ?? $this->servers->findOneBy(['name' => $needle]))
            : ($this->servers->findBy([], ['name' => 'ASC'])[0] ?? null);

        if (!$server instanceof GameServer) {
            $io->error('no such server');

            return Command::FAILURE;
        }

        $guildId = $server->getDiscordConfig()?->getGuildId();
        $applicationId = trim((string) $this->settings->get(AppSetting::DISCORD_APPLICATION_ID));

        if ($guildId === null || $applicationId === '') {
            $io->error('this server has no Discord guild, or no application id is configured');

            return Command::FAILURE;
        }

        try {
            if ($input->getOption('register') === true) {
                $this->discord->registerCommands($applicationId, $guildId, CommandCatalogue::all());
                $io->writeln('catalogue sent');
            }

            $held = $this->discord->guildCommands($applicationId, $guildId);
        } catch (DiscordException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        if ($held === []) {
            $io->warning('Discord holds no commands for this guild');

            return Command::SUCCESS;
        }

        $io->table(
            ['command', 'subcommands'],
            array_map(
                static fn (array $command): array => [
                    '/'.($command['name'] ?? '?'),
                    implode(', ', array_map(
                        static fn (array $option): string => (string) ($option['name'] ?? '?'),
                        $command['options'] ?? [],
                    )),
                ],
                $held,
            ),
        );

        $io->success(sprintf('%d commands in guild %s', count($held), $guildId));

        return Command::SUCCESS;
    }
}
