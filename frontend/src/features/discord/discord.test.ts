import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

import {
  activeEventCount,
  setupGaps,
  ungrantedCommands,
  type DiscordCommandSetting,
  type DiscordEventSetting,
  type DiscordSetup,
} from './discord'

function setup(overrides: Partial<DiscordSetup> = {}): DiscordSetup {
  return {
    tokenConfigured: true,
    applicationId: '111111111111111111',
    publicKeyConfigured: true,
    guildId: '222222222222222222',
    chatChannelId: null,
    chatScope: 'general',
    relayIntoGame: false,
    commandsEnabled: true,
    events: [],
    commands: [],
    ...overrides,
  }
}

function event(overrides: Partial<DiscordEventSetting> = {}): DiscordEventSetting {
  return {
    type: 'moderation.kick',
    adminAction: true,
    enabled: false,
    active: false,
    channelId: null,
    template: null,
    defaultTemplate: 'x',
    tokens: ['player'],
    ...overrides,
  }
}

function command(overrides: Partial<DiscordCommandSetting> = {}): DiscordCommandSetting {
  return {
    name: 'spieler.kick',
    command: 'spieler',
    subcommand: 'kick',
    permission: 'players.kick',
    roleIds: [],
    ...overrides,
  }
}

describe('what is missing before anything can work', () => {
  it('is nothing when all four pieces are there', () => {
    expect(setupGaps(setup())).toEqual([])
  })

  /** Each of the four is fixed in a different place, so each is named. */
  it('names each missing piece separately', () => {
    expect(setupGaps(setup({ tokenConfigured: false }))).toEqual(['token'])
    expect(setupGaps(setup({ applicationId: '' }))).toEqual(['applicationId'])
    expect(setupGaps(setup({ publicKeyConfigured: false }))).toEqual(['publicKey'])
    expect(setupGaps(setup({ guildId: '' }))).toEqual(['guild'])
  })

  it('names all of them on a fresh install', () => {
    const fresh = setup({
      tokenConfigured: false,
      applicationId: '',
      publicKeyConfigured: false,
      guildId: '',
    })

    expect(setupGaps(fresh)).toEqual(['token', 'applicationId', 'publicKey', 'guild'])
  })
})

describe('the active count', () => {
  /**
   * Counted on `active`, not `enabled`: a switch on with no channel
   * sends nothing, and counting it would say the notifications work.
   */
  it('ignores an event switched on with no channel', () => {
    const events = [
      event({ enabled: true, active: false }),
      event({ type: 'moderation.ban', enabled: true, active: true }),
    ]

    expect(activeEventCount(events)).toBe(1)
  })

  it('is zero when nothing is configured', () => {
    expect(activeEventCount([event(), event()])).toBe(0)
  })
})

describe('commands nobody can run', () => {
  it('finds the ones with no role assigned', () => {
    const commands = [command(), command({ name: 'spieler.bannen', roleIds: ['333'] })]

    expect(ungrantedCommands(commands).map((each) => each.name)).toEqual(['spieler.kick'])
  })
})

/**
 * Every announceable event needs a readable name, or the page shows a
 * technical key where a sentence belongs.
 */
describe('the event names', () => {
  it('names every event the backend can announce, in both locales', () => {
    // The moderation keys are written as `'moderation.'.ModerationAction::KICK`,
    // so the constant has to be resolved rather than matched as a
    // literal — six of the types are literals and the rest are not.
    const source = readFileSync('../backend/src/Server/Discord/NotifiableEvents.php', 'utf8')
    const actions = readFileSync('../backend/src/Entity/ModerationAction.php', 'utf8')

    const constants = new Map(
      [...actions.matchAll(/public const ([A-Z_]+) = '([a-z_]+)';/g)].map((match) => [
        match[1],
        match[2],
      ]),
    )

    const types = [
      ...[...source.matchAll(/'((?:bridge|server|panel)\.[a-z]+)'\s*=>/g)].map((match) => match[1]),
      ...[...source.matchAll(/'moderation\.'\.ModerationAction::([A-Z_]+)/g)].map(
        (match) => `moderation.${constants.get(match[1]) ?? match[1]}`,
      ),
    ]

    expect(types.length).toBeGreaterThan(20)

    for (const locale of ['de', 'en']) {
      const names = JSON.parse(readFileSync(`src/i18n/locales/${locale}.json`, 'utf8')).discord
        .eventNames

      const missing = types.filter((type) => names[type] === undefined)

      expect(missing, `${locale} is missing: ${missing.join(', ')}`).toEqual([])
    }
  })
})
