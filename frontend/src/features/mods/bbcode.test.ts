import { describe, expect, it } from 'vitest'

import { blocksOf, hasRemoteImages, type Block, type Span } from './bbcode'

const kinds = (blocks: Block[]) => blocks.map((block) => block.kind)

const words = (spans: Span[]) => spans.map((span) => span.text).join('')

const text = (text: string): Span => ({ kind: 'text', text })

describe('blocksOf', () => {
  it('keeps a single newline, so two labelled ids stay on their own lines', () => {
    const blocks = blocksOf('Workshop ID: 2503622437\nMod ID: SkillRecoveryJournal')

    expect(blocks).toEqual([
      { kind: 'text', spans: [text('Workshop ID: 2503622437\nMod ID: SkillRecoveryJournal')] },
    ])
  })

  it('keeps a picture rather than dropping the paragraph that holds it', () => {
    expect(blocksOf('[img]https://example.test/banner.png[/img]')).toEqual([
      {
        kind: 'pictures',
        pictures: [{ src: 'https://example.test/banner.png', href: null }],
        caption: [],
      },
    ])
  })

  it('keeps the link wrapped around a picture', () => {
    expect(
      blocksOf('[url=https://ko-fi.com/someone][img]https://example.test/kofi.png[/img][/url]'),
    ).toEqual([
      {
        kind: 'pictures',
        pictures: [{ src: 'https://example.test/kofi.png', href: 'https://ko-fi.com/someone' }],
        caption: [],
      },
    ])
  })

  it('names a linked picture once, not twice', () => {
    const blocks = blocksOf('[url=https://ko-fi.com/a][img]https://example.test/one.png[/img][/url]')

    expect(blocks[0].kind === 'pictures' && blocks[0].pictures).toHaveLength(1)
  })

  it('keeps the words that came beside a picture', () => {
    const blocks = blocksOf('[img]https://example.test/a.png[/img] Download here')

    expect(blocks[0].kind === 'pictures' && words(blocks[0].caption)).toBe('Download here')
  })

  it('drops a heading used only as a spacer', () => {
    expect(blocksOf('[b][i][h1] [/h1][/i][/b]')).toEqual([])
  })

  it('reads a heading and keeps the level Steam gave it', () => {
    expect(blocksOf('[h2]Craftable journals[/h2]')).toEqual([
      { kind: 'heading', level: 2, spans: [text('Craftable journals')] },
    ])
  })

  it('reads a list', () => {
    const blocks = blocksOf('[list][*]One[*]Two[/list]')

    expect(blocks[0].kind === 'list' && blocks[0].items.map(words)).toEqual(['One', 'Two'])
  })

  it('is empty for a description of whitespace', () => {
    expect(blocksOf('   \n\n  ')).toEqual([])
  })

  /**
   * The panel's CSP carries `upgrade-insecure-requests`, so the browser
   * fetches this over https regardless — refusing it here would drop a
   * picture that does in fact load.
   */
  it('keeps a picture whose author typed http', () => {
    expect(blocksOf('[img]http://example.test/a.png[/img]')).toEqual([
      {
        kind: 'pictures',
        pictures: [{ src: 'http://example.test/a.png', href: null }],
        caption: [],
      },
    ])
  })

  it('leaves no bare address behind when the marker holds no url', () => {
    expect(blocksOf('[img]not-a-url[/img]')).toEqual([])
  })

  /**
   * 108 of these across ten mods, and stripping them to the label left
   * sentences like "get the patch here" pointing at nothing.
   */
  it('keeps a link in prose addressable', () => {
    const blocks = blocksOf('Get the patch [url=https://example.test/patch]here[/url] first.')

    expect(blocks[0].kind === 'text' && blocks[0].spans).toEqual([
      text('Get the patch '),
      { kind: 'link', text: 'here', href: 'https://example.test/patch' },
      text(' first.'),
    ])
  })

  it('shows the address itself when a link carries no label', () => {
    const blocks = blocksOf('[url=https://example.test/x][/url]')

    expect(blocks[0].kind === 'text' && blocks[0].spans).toEqual([
      { kind: 'link', text: 'https://example.test/x', href: 'https://example.test/x' },
    ])
  })

  it('refuses a javascript: target but keeps its words', () => {
    const blocks = blocksOf('[url=javascript:alert(1)]click me[/url]')

    expect(blocks[0].kind === 'text' && blocks[0].spans).toEqual([text('click me')])
  })

  it('keeps bold and italics apart from plain words', () => {
    const blocks = blocksOf('A [b]loud[/b] and [i]quiet[/i] word.')

    expect(blocks[0].kind === 'text' && blocks[0].spans).toEqual([
      text('A '),
      { kind: 'strong', text: 'loud' },
      text(' and '),
      { kind: 'emphasis', text: 'quiet' },
      text(' word.'),
    ])
  })

  it('reads a quote as a quote', () => {
    expect(blocksOf('[quote=someone]Words[/quote]')).toEqual([
      { kind: 'quote', spans: [text('Words')] },
    ])
  })

  it('keeps code verbatim, tags and all', () => {
    expect(blocksOf('[code]Mods=A;B[/code]')).toEqual([{ kind: 'code', text: 'Mods=A;B' }])
  })

  /**
   * The description that showed the fault: 1573 characters from Steam
   * arriving as three visible lines, because five of its eight
   * paragraphs were a link around a banner and nothing else.
   */
  it('keeps every paragraph of a real description that is mostly banners', () => {
    const description = [
      '[h1][i]Lore-friendly(ish) solution to the loss of a character.[/i][/h1]',
      '[img]https://raw.githubusercontent.com/x/newRequired.png[/img]',
      '[url=https://ko-fi.com/chuckleberryfinn][img]https://raw.githubusercontent.com/x/kofi.png[/img][/url]',
      '[url=https://discord.gg/SReMnbV4V7][img]https://i.imgur.com/2Ip8ifN.png[/img][/url]',
      '[b][i][h1] [/h1][/i][/b]',
      '[h3]Copyright 2026 Chuckleberry Finn.[/h3]',
      'Workshop ID: 2503622437\nMod ID: SkillRecoveryJournal',
    ].join('\r\n\r\n')

    const blocks = blocksOf(description)

    expect(kinds(blocks)).toEqual([
      'heading',
      'pictures',
      'pictures',
      'pictures',
      'heading',
      'text',
    ])

    // The three link targets are what used to vanish entirely.
    const links = blocks
      .filter((block) => block.kind === 'pictures')
      .flatMap((block) => (block.kind === 'pictures' ? block.pictures : []))
      .map((picture) => picture.href)

    expect(links).toEqual([
      null,
      'https://ko-fi.com/chuckleberryfinn',
      'https://discord.gg/SReMnbV4V7',
    ])
  })
})

describe('hasRemoteImages', () => {
  it('is true when the description draws from another server', () => {
    expect(hasRemoteImages('[img]https://example.test/a.png[/img]')).toBe(true)
  })

  it('is false for a description of plain words', () => {
    expect(hasRemoteImages('Just a sentence about a journal.')).toBe(false)
  })
})
