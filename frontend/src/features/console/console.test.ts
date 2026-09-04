import { describe, expect, it } from 'vitest'

import { checkSyntax, type ServerCommand } from './console'

const command = (overrides: Partial<ServerCommand> & { name: string }): ServerCommand => ({
  description: '',
  usage: null,
  parameters: [],
  example: null,
  dangerous: false,
  ...overrides,
})

// Shaped after what a live Build 42 server reports through "help".
const COMMANDS: ServerCommand[] = [
  command({ name: 'players', description: 'List connected players.' }),
  command({
    name: 'additem',
    usage: '/additem "username" "module.item" count',
    parameters: [
      { name: 'username', quoted: true, optional: true },
      { name: 'module.item', quoted: true, optional: false },
      { name: 'count', quoted: false, optional: true },
    ],
  }),
  command({
    name: 'kick',
    usage: '/kickuser "username" -r "reason"',
    parameters: [
      { name: 'username', quoted: true, optional: false },
      { name: '-r', quoted: false, optional: false },
      { name: 'reason', quoted: true, optional: false },
    ],
  }),
  command({ name: 'quit', dangerous: true }),
]

describe('checkSyntax', () => {
  it('accepts an empty line rather than complaining while the field is blank', () => {
    expect(checkSyntax('', COMMANDS)).toBeNull()
    expect(checkSyntax('   ', COMMANDS)).toBeNull()
  })

  it('accepts a command typed with the in-game leading slash', () => {
    expect(checkSyntax('/players', COMMANDS)).toBeNull()
  })

  it('reports a command the server never listed', () => {
    expect(checkSyntax('teleport bob', COMMANDS)).toEqual({
      kind: 'unknownCommand',
      command: 'teleport',
    })
  })

  it('matches the command whatever case it was typed in', () => {
    expect(checkSyntax('PLAYERS', COMMANDS)).toBeNull()
  })

  it('allows anything after a command whose help documents no syntax', () => {
    expect(checkSyntax('players extra arguments', COMMANDS)).toBeNull()
  })

  it('accepts a command given exactly its required arguments', () => {
    expect(checkSyntax('additem "Base.Axe"', COMMANDS)).toBeNull()
  })

  it('accepts a command given every argument including the optional ones', () => {
    expect(checkSyntax('additem "bob" "Base.Axe" 5', COMMANDS)).toBeNull()
  })

  it('names the argument that is missing', () => {
    expect(checkSyntax('kick "bob"', COMMANDS)).toEqual({
      kind: 'tooFewArguments',
      expected: 3,
      given: 1,
      missing: '-r',
    })
  })

  it('reports more arguments than the syntax allows', () => {
    expect(checkSyntax('additem "bob" "Base.Axe" 5 extra', COMMANDS)).toEqual({
      kind: 'tooManyArguments',
      expected: 3,
      given: 4,
    })
  })

  it('treats a quoted run as one argument, spaces and all', () => {
    expect(checkSyntax('kick "Bob the Builder" -r "being rude"', COMMANDS)).toBeNull()
  })

  it('reports a quote that was never closed', () => {
    expect(checkSyntax('kick "bob', COMMANDS)).toEqual({ kind: 'unbalancedQuote' })
  })

  it('counts an empty quoted argument as given', () => {
    expect(checkSyntax('kick "" -r ""', COMMANDS)).toBeNull()
  })

  it('ignores runs of whitespace between arguments', () => {
    expect(checkSyntax('kick    "bob"   -r   "rude"', COMMANDS)).toBeNull()
  })
})
