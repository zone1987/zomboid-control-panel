import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

/**
 * Every action type the backend records needs a name in both locales.
 *
 * Four were missing when the dossier added them — `ability`,
 * `experience`, `heal`, `statistic` — and the history would have shown
 * the raw identifier, which reads as a bug on screen rather than as a
 * missing translation.
 */
describe('the moderation action names', () => {
  const entity = readFileSync('../backend/src/Entity/ModerationAction.php', 'utf8')

  const declared = [...entity.matchAll(/public const [A-Z_]+ = '([a-z_]+)'/g)].map(
    (match) => match[1],
  )

  it('reads the entity at all', () => {
    expect(declared.length).toBeGreaterThan(10)
  })

  it('names every recorded action in both locales', () => {
    const de = JSON.parse(readFileSync('src/i18n/locales/de.json', 'utf8'))
    const en = JSON.parse(readFileSync('src/i18n/locales/en.json', 'utf8'))

    for (const action of declared) {
      expect(de.players.actionName[action], `${action} missing from de`).toBeTruthy()
      expect(en.players.actionName[action], `${action} missing from en`).toBeTruthy()
    }
  })
})
