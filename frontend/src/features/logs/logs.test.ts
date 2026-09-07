import { describe, expect, it } from 'vitest'

import { parseLine } from './logs'

describe('parseLine', () => {
  it('separates the stamp and level from a chat log line', () => {
    expect(parseLine('[04-09-26 18:28:08.836][info] Chat server initialised.', 1)).toEqual({
      id: 1,
      timestamp: '04-09-26 18:28:08.836',
      level: 'info',
      body: 'Chat server initialised.',
    })
  })

  it('reads a connection log line, which carries no level', () => {
    expect(parseLine('[04-09-26 18:29:33.422] event="RakNet" message="new"', 2)).toEqual({
      id: 2,
      timestamp: '04-09-26 18:29:33.422',
      level: null,
      body: 'event="RakNet" message="new"',
    })
  })

  it('recognises the levels worth colouring differently', () => {
    expect(parseLine('[t][ERROR] broke', 1).level).toBe('error')
    expect(parseLine('[t][WARN] careful', 1).level).toBe('warn')
    expect(parseLine('[t][debug] detail', 1).level).toBe('info')
  })

  it('leaves a level it does not know as none', () => {
    expect(parseLine('[t][trace] detail', 1).level).toBeNull()
  })

  it('keeps a line that follows neither shape', () => {
    expect(parseLine('a bare line', 3)).toEqual({
      id: 3,
      timestamp: null,
      level: null,
      body: 'a bare line',
    })
  })

  it('keeps an empty body rather than dropping the line', () => {
    expect(parseLine('[04-09-26 18:28:08.836][info]', 4).body).toBe('')
  })
})
