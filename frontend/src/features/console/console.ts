import { apiFetch } from '@/lib/api'

export type CommandParameter = {
  name: string
  quoted: boolean
  optional: boolean
}

export type ServerCommand = {
  name: string
  description: string
  usage: string | null
  parameters: CommandParameter[]
  example: string | null
  dangerous: boolean
}

export type ConsoleResult = {
  status: string
  command: string
  reply: string
}

export function listCommands(serverId: string, refresh = false): Promise<{ items: ServerCommand[] }> {
  const query = refresh ? '?refresh=1' : ''

  return apiFetch(`/servers/${serverId}/console/commands${query}`)
}

export function runCommand(serverId: string, command: string): Promise<ConsoleResult> {
  return apiFetch(`/servers/${serverId}/console`, { method: 'POST', body: { command } })
}

export type SyntaxProblem =
  | { kind: 'unknownCommand'; command: string }
  | { kind: 'tooFewArguments'; expected: number; given: number; missing: string }
  | { kind: 'tooManyArguments'; expected: number; given: number }
  | { kind: 'unbalancedQuote' }

/**
 * Checks a typed line against the syntax the server reported, so a
 * mistyped command fails here rather than silently doing nothing.
 * Returns null when the line looks usable — including when the command
 * is known but documents no syntax at all.
 */
export function checkSyntax(line: string, commands: ServerCommand[]): SyntaxProblem | null {
  const trimmed = line.trim().replace(/^\//, '')

  if (trimmed === '') {
    return null
  }

  const tokens = tokenise(trimmed)

  if (tokens === null) {
    return { kind: 'unbalancedQuote' }
  }

  const [verb, ...args] = tokens
  const command = commands.find((entry) => entry.name === verb.toLowerCase())

  if (command === undefined) {
    return { kind: 'unknownCommand', command: verb }
  }

  // A command whose help line carries no usage tells us nothing about
  // its arguments, so anything is allowed.
  if (command.usage === null || command.parameters.length === 0) {
    return null
  }

  const required = command.parameters.filter((parameter) => !parameter.optional)

  if (args.length < required.length) {
    return {
      kind: 'tooFewArguments',
      expected: required.length,
      given: args.length,
      missing: required[args.length]?.name ?? '',
    }
  }

  if (args.length > command.parameters.length) {
    return {
      kind: 'tooManyArguments',
      expected: command.parameters.length,
      given: args.length,
    }
  }

  return null
}

/** Splits on whitespace, keeping quoted runs together. Null when a quote is left open. */
function tokenise(line: string): string[] | null {
  const tokens: string[] = []
  let current = ''
  let inQuotes = false
  let started = false

  for (const character of line) {
    if (character === '"') {
      inQuotes = !inQuotes
      started = true

      continue
    }

    if (!inQuotes && /\s/.test(character)) {
      if (started || current !== '') {
        tokens.push(current)
        current = ''
        started = false
      }

      continue
    }

    current += character
    started = true
  }

  if (inQuotes) {
    return null
  }

  if (started || current !== '') {
    tokens.push(current)
  }

  return tokens
}
