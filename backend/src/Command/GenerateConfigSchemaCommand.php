<?php

declare(strict_types=1);

namespace App\Command;

use App\Server\Config\SchemaWriter;
use App\Server\Config\SchemaExtractor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Generates the sandbox and INI schema from a local game installation.
 *
 * A developer tool, run by hand when the game's build changes; the panel
 * never runs it and never needs the installation. Its output is
 * committed, and `ConfigSchemaTest` compares the committed fixture
 * against a fresh extraction so a game update surfaces as a red test
 * rather than as wrong bounds on a live server.
 */
#[AsCommand(
    name: 'app:config:schema',
    description: 'Regenerate the sandbox and server.ini schema from a game installation',
)]
final class GenerateConfigSchemaCommand extends Command
{
    /**
     * Written by `backend/tools/dump-game-config.sh` on the host, which
     * is the only place with both a mounted installation and a JDK; this
     * container has neither, and should not.
     */
    private const DEFAULT_DUMP = __DIR__.'/../../var/game-config.json';

    protected function configure(): void
    {
        $this->addOption(
            'dump',
            'd',
            InputOption::VALUE_REQUIRED,
            'Path to the game dump written by backend/tools/dump-game-config.sh',
            self::DEFAULT_DUMP,
        );

        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what was read, write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dump = (string) $input->getOption('dump');

        try {
            $schema = SchemaExtractor::fromDump($dump)->extract();
        } catch (\RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->definitionList(
            ['build' => $schema['buildId'] ?? 'unknown (not a Steam copy)'],
            ['sandbox options' => (string) count($schema['sandbox'])],
            ['sandbox groups' => (string) count($schema['sandboxGroups'])],
            ['ini options' => (string) count($schema['ini'])],
            ['ini groups' => (string) count($schema['iniGroups'])],
        );

        $this->reportGaps($io, $schema);

        if ($input->getOption('dry-run') === true) {
            $io->note('dry run: nothing written');

            return Command::SUCCESS;
        }

        $written = (new SchemaWriter())->write($schema);

        foreach ($written as $path) {
            $io->writeln(sprintf('  wrote %s', $path));
        }

        $io->success('schema regenerated; review the diff before committing');

        return Command::SUCCESS;
    }

    /**
     * What could not be resolved, named rather than silently defaulted.
     *
     * @param array{sandbox: array<string, array<string, mixed>>, ini: array<string, array<string, mixed>>, ...} $schema
     */
    private function reportGaps(SymfonyStyle $io, array $schema): void
    {
        $untyped = array_keys(array_filter(
            $schema['sandbox'],
            static fn (array $option): bool => $option['type'] === null,
        ));

        $unlabelled = array_keys(array_filter(
            $schema['sandbox'],
            static fn (array $option): bool => ($option['labels']['EN'] ?? null) === null,
        ));

        $iniUntyped = array_keys(array_filter(
            $schema['ini'],
            static fn (array $option): bool => $option['type'] === null,
        ));

        foreach ([
            'sandbox options with no type' => $untyped,
            'sandbox options with no English label' => $unlabelled,
            'ini options with no type' => $iniUntyped,
        ] as $label => $keys) {
            if ($keys === []) {
                continue;
            }

            $io->warning(sprintf('%s (%d): %s', $label, count($keys), implode(', ', array_slice($keys, 0, 12))));
        }
    }
}
