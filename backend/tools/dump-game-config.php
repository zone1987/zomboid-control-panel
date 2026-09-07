#!/usr/bin/env php
<?php

/**
 * Collects the raw game data the config schema is built from.
 *
 * Runs on the *host*, where the installation is mounted and `javap`
 * exists; neither is true inside ddev, and neither should be — the panel
 * must never need a game installation at run time.
 *
 * Writes one JSON file that `app:config:schema` then reads inside the
 * container. Splitting it this way is not a convenience: the extraction
 * needs a JDK and a mounted game, the generation needs the project's
 * autoloader, and no single environment here has both.
 *
 * Usage, from the project root:
 *
 *     php backend/tools/dump-game-config.php [installation] > backend/var/game-config.json
 *
 * There is no PHP on this host either, so in practice:
 *
 *     bash backend/tools/dump-game-config.sh
 */

declare(strict_types=1);

$installation = $argv[1] ?? '/Volumes/ESD-USB/ProjectZomboid';

$classes = [
    'zombie.SandboxOptions',
    'zombie.network.ServerOptions',
    'zombie.SandboxOptions$Basement',
    'zombie.SandboxOptions$Map',
    'zombie.SandboxOptions$ZombieLore',
    'zombie.SandboxOptions$MultiplierConfig',
    'zombie.SandboxOptions$ZombieConfig',
];

$files = [
    'settingsScreen' => 'media/lua/client/OptionScreens/ServerSettingsScreen.lua',
    'defaults' => 'media/lua/shared/Sandbox/Apocalypse.lua',
    'sandboxEN' => 'media/lua/shared/Translate/EN/Sandbox.json',
    'sandboxDE' => 'media/lua/shared/Translate/DE/Sandbox.json',
    'uiEN' => 'media/lua/shared/Translate/EN/UI.json',
    'uiDE' => 'media/lua/shared/Translate/DE/UI.json',
];

$dump = ['installation' => $installation, 'javap' => [], 'files' => []];

foreach ($classes as $class) {
    foreach (['fields' => ['-p'], 'code' => ['-p', '-c']] as $kind => $flags) {
        $command = array_merge(['javap', '-cp', $installation.'/projectzomboid.jar'], $flags, [$class]);
        $escaped = implode(' ', array_map('escapeshellarg', $command));
        $output = shell_exec($escaped.' 2>/dev/null');

        if (!is_string($output) || trim($output) === '') {
            fwrite(STDERR, sprintf("javap produced nothing for %s (%s)\n", $class, $kind));
            exit(1);
        }

        $dump['javap'][$class][$kind] = $output;
    }
}

foreach ($files as $name => $relative) {
    $raw = @file_get_contents($installation.'/'.$relative);

    if (!is_string($raw)) {
        fwrite(STDERR, sprintf("cannot read %s\n", $relative));
        exit(1);
    }

    $dump['files'][$name] = $raw;
}

foreach (['steamapps/appmanifest_108600.acf', '../../appmanifest_108600.acf'] as $candidate) {
    $raw = @file_get_contents($installation.'/'.$candidate);

    if (is_string($raw) && preg_match('/"buildid"\s+"(\d+)"/', $raw, $match) === 1) {
        $dump['buildId'] = $match[1];
        break;
    }
}

echo json_encode($dump, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
