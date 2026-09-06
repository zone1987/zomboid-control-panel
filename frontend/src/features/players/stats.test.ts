import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

import { PRIMARY_STATS } from './players'

/**
 * The statistic names have to agree in three places: the game's own
 * registry, the bridge's list, and the panel's labels.
 *
 * They are read by id — `CharacterStat.getById('Hunger')` — so a name
 * that drifts anywhere offers a control the server cannot resolve, or
 * shows a raw identifier where a label belongs.
 */
describe('the character statistics', () => {
  const php = readFileSync('../backend/src/Server/Bridge/BridgeCommand.php', 'utf8')

  const declared = [
    ...(/public const CHARACTER_STATS = \[(.*?)\];/s.exec(php) as RegExpExecArray)[1].matchAll(
      /'(\w+)'/g,
    ),
  ].map((match) => match[1])

  it('reads the bridge list at all', () => {
    expect(declared).toHaveLength(24)
  })

  it('labels every one in both locales', () => {
    const de = JSON.parse(readFileSync('src/i18n/locales/de.json', 'utf8'))
    const en = JSON.parse(readFileSync('src/i18n/locales/en.json', 'utf8'))

    for (const stat of declared) {
      expect(de.players.stats[stat], `${stat} missing from de`).toBeTruthy()
      expect(en.players.stats[stat], `${stat} missing from en`).toBeTruthy()
    }
  })

  /**
   * The nine shown first must be nine the server actually registers —
   * a typo there would silently show nothing rather than a wrong value.
   */
  it('shows only statistics the bridge knows', () => {
    for (const stat of PRIMARY_STATS) {
      expect(declared, `${stat} is shown but not declared`).toContain(stat)
    }
  })

  /** And the same list the Lua asks the game for. */
  it('matches the list the bridge sends to the game', () => {
    const lua = readFileSync('../backend/resources/bridge/ZomboidControlBridge.lua', 'utf8')

    const inLua = [
      ...(/local CHARACTER_STATS = \{(.*?)\}/s.exec(lua) as RegExpExecArray)[1].matchAll(
        /"(\w+)"/g,
      ),
    ].map((match) => match[1])

    expect(inLua).toEqual(declared)
  })
})
