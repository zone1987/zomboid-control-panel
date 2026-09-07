#!/usr/bin/env bash
# Collects the raw game data for `app:config:schema`, using only tools
# the host actually has: a JDK for javap and python3 for the JSON.
#
# There is no PHP on the host and no javap inside ddev, which is why this
# step exists at all. Run it from the project root:
#
#     bash backend/tools/dump-game-config.sh [installation]
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
OUT="$ROOT/backend/var/game-config.json"

# installations/server holds the game itself -- the jar, media/lua and
# the translations -- and sits inside the project, so the ddev container
# can read it too. installations/local is the Zomboid *data* folder
# (Saves, Logs, options.ini) and has no jar; it is not a source here.
# A mounted volume is the fallback for a machine with neither.
for CANDIDATE in "${1:-}" "$ROOT/installations/server" "$ROOT/installations/local" "/Volumes/ESD-USB/ProjectZomboid"; do
  if [ -n "$CANDIDATE" ] && [ -r "$CANDIDATE/projectzomboid.jar" ]; then
    INSTALLATION="$CANDIDATE"
    break
  fi
done

if [ -z "${INSTALLATION:-}" ]; then
  echo "no projectzomboid.jar found" >&2
  echo "place the game in $ROOT/installations/server, or pass a path" >&2
  exit 1
fi

echo "reading $INSTALLATION" >&2

mkdir -p "$(dirname "$OUT")"

INSTALLATION="$INSTALLATION" OUT="$OUT" python3 - <<'PY'
import json, os, subprocess, sys

installation = os.environ['INSTALLATION']
jar = f'{installation}/projectzomboid.jar'

classes = [
    'zombie.SandboxOptions',
    'zombie.network.ServerOptions',
    'zombie.SandboxOptions$Basement',
    'zombie.SandboxOptions$Map',
    'zombie.SandboxOptions$ZombieLore',
    'zombie.SandboxOptions$MultiplierConfig',
    'zombie.SandboxOptions$ZombieConfig',
    # Two options are typed by a real Java enum rather than a value
    # count (newEnumOption(name, Class, Enum)), so their choices and
    # default only exist in these classes.
    'zombie.characters.InjurySeverity',
    'zombie.characters.DamageModifier',
    # Its static initialiser opens with the game version as two pushes
    # (42, 20 for B42.20), which is the provenance stamp when the copy
    # did not come from Steam and so has no appmanifest.
    'zombie.core.Core',
]

files = {
    'settingsScreen': 'media/lua/client/OptionScreens/ServerSettingsScreen.lua',
    'defaults': 'media/lua/shared/Sandbox/Apocalypse.lua',
    'sandboxEN': 'media/lua/shared/Translate/EN/Sandbox.json',
    'sandboxDE': 'media/lua/shared/Translate/DE/Sandbox.json',
    'uiEN': 'media/lua/shared/Translate/EN/UI.json',
    'uiDE': 'media/lua/shared/Translate/DE/UI.json',
}

dump = {'installation': installation, 'javap': {}, 'files': {}}

for cls in classes:
    dump['javap'][cls] = {}
    for kind, flags in (('fields', ['-p']), ('code', ['-p', '-c'])):
        out = subprocess.run(['javap', '-cp', jar, *flags, cls],
                             capture_output=True, text=True).stdout
        if not out.strip():
            sys.exit(f'javap produced nothing for {cls} ({kind})')
        dump['javap'][cls][kind] = out

for name, relative in files.items():
    with open(f'{installation}/{relative}', encoding='utf-8', errors='replace') as handle:
        dump['files'][name] = handle.read()

for candidate in ('steamapps/appmanifest_108600.acf', '../../appmanifest_108600.acf'):
    try:
        with open(f'{installation}/{candidate}', encoding='utf-8', errors='replace') as handle:
            raw = handle.read()
    except OSError:
        continue
    import re
    found = re.search(r'"buildid"\s+"(\d+)"', raw)
    if found:
        dump['buildId'] = found.group(1)
        break

with open(os.environ['OUT'], 'w', encoding='utf-8') as handle:
    json.dump(dump, handle, ensure_ascii=False)

print(f"wrote {os.environ['OUT']}", file=sys.stderr)
for cls, kinds in dump['javap'].items():
    print(f"  {cls}: {sum(len(v) for v in kinds.values())} bytes", file=sys.stderr)
PY
