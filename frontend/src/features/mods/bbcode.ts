/**
 * Parsing for a workshop description, which arrives as BBCode.
 *
 * Kept apart from the component so it can be tested as what it is: a
 * function from a string to blocks. The component then draws them and
 * decides nothing.
 *
 * The tags handled here are the ones Steam's own descriptions use,
 * counted across a sample rather than guessed: url (108), img (96),
 * b (66), list and * (51), h1–h3 (48), i (8), quote (6), code (4).
 */

export type Picture = { src: string; href: string | null }

/** A run of description text: plain, emphasised, or a link. */
export type Span =
  | { kind: 'text'; text: string }
  | { kind: 'strong'; text: string }
  | { kind: 'emphasis'; text: string }
  | { kind: 'link'; text: string; href: string }

export type Block =
  | { kind: 'heading'; level: number; spans: Span[] }
  | { kind: 'list'; items: Span[][] }
  | { kind: 'pictures'; pictures: Picture[]; caption: Span[] }
  | { kind: 'quote'; spans: Span[] }
  | { kind: 'code'; text: string }
  | { kind: 'text'; spans: Span[] }

// http is accepted because the panel's own CSP carries
// `upgrade-insecure-requests`: the browser fetches it over https
// anyway, so refusing it would drop a picture that does load.
const IMAGE = /\[img\]\s*(https?:\/\/[^\s[\]]+)\s*\[\/img\]/gi

const LINKED_IMAGE =
  /\[url=(https?:\/\/[^\]]+)\]\s*\[img\]\s*(https?:\/\/[^\s[\]]+)\s*\[\/img\]\s*\[\/url\]/gi

/** Whether the description draws anything from somebody else's server. */
export function hasRemoteImages(text: string): boolean {
  return new RegExp(IMAGE.source, 'i').test(text)
}

export function blocksOf(text: string): Block[] {
  const cleaned = text
    // Steam sends CRLF, so a paragraph split on \n{2,} alone matched
    // nothing and the whole description collapsed into one block.
    .replace(/\r\n/g, '\n')
    .replace(/\[previewyoutube[^\]]*\][^[]*\[\/previewyoutube\]/gi, '')
    .replace(/\[\/?(?:table|tr|td|th)\]/gi, '\n')
    // Anything that is not a picture address at all: the marker goes
    // rather than leaving a bare URL sitting in the prose.
    .replace(/\[img\]\s*(?!https?:\/\/)[^[\]]*\[\/img\]/gi, '')
    // A heading is its own block whatever separates it. Steam renders
    // `[h1]…[/h1]\n[h2]…[/h2]` as two headings under one another --
    // splitting on blank lines alone put both in one paragraph, where
    // neither was recognised and both came out as grey prose.
    .replace(/(\[h[1-6]\][\s\S]*?\[\/h[1-6]\])/gi, '\n\n$1\n\n')

  const blocks: Block[] = []

  for (const raw of cleaned.split(/\n{2,}/)) {
    const paragraph = raw.trim()

    if (paragraph === '') {
      continue
    }

    const heading = /^\[h([1-6])\](.*?)\[\/h\1\]$/is.exec(paragraph)

    if (heading !== null) {
      const spans = spansOf(heading[2])

      // `[h1] [/h1]` is a spacer, and an empty heading draws a gap in a
      // font weight nobody asked for.
      if (spans.length > 0) {
        blocks.push({ kind: 'heading', level: Number(heading[1]), spans })
      }

      continue
    }

    const code = /^\[code\]([\s\S]*?)\[\/code\]$/i.exec(paragraph)

    if (code !== null) {
      const body = code[1].trim()

      if (body !== '') {
        blocks.push({ kind: 'code', text: body })
      }

      continue
    }

    const quote = /^\[quote(?:=[^\]]*)?\]([\s\S]*?)\[\/quote\]$/i.exec(paragraph)

    if (quote !== null) {
      const spans = spansOf(quote[1])

      if (spans.length > 0) {
        blocks.push({ kind: 'quote', spans })
      }

      continue
    }

    if (/\[list\]/i.test(paragraph)) {
      const items = [...paragraph.matchAll(/\[\*\]([\s\S]*?)(?=\[\*\]|\[\/list\]|$)/g)]
        .map(([, item]) => spansOf(item))
        .filter((item) => item.length > 0)

      if (items.length > 0) {
        blocks.push({ kind: 'list', items })
        continue
      }
    }

    const pictures = picturesOf(paragraph)

    if (pictures.length > 0) {
      blocks.push({
        kind: 'pictures',
        pictures,
        caption: spansOf(withoutImages(paragraph).trim()),
      })
      continue
    }

    const spans = spansOf(paragraph)

    if (spans.length > 0) {
      blocks.push({ kind: 'text', spans })
    }
  }

  return blocks
}

/**
 * The pictures a paragraph carries, with the link around them kept.
 *
 * A banner wrapped in `[url]` is usually the author's Ko-fi, Discord or
 * bug tracker — dropping the link turned five of one mod's eight
 * paragraphs into nothing at all, which is how a 1573-character
 * description came out as three lines.
 */
function picturesOf(paragraph: string): Picture[] {
  const pictures: Picture[] = []
  const linked = new Set<string>()

  for (const [, href, src] of paragraph.matchAll(LINKED_IMAGE)) {
    pictures.push({ src, href })
    linked.add(src)
  }

  for (const [, src] of paragraph.matchAll(IMAGE)) {
    if (!linked.has(src)) {
      pictures.push({ src, href: null })
    }
  }

  return pictures
}

function withoutImages(paragraph: string): string {
  return paragraph.replace(LINKED_IMAGE, '').replace(IMAGE, '')
}

const INLINE_TAG = /\[(b|i|u|url)(?:=([^\]]*))?\]([\s\S]*?)\[\/\1\]/i

/**
 * Splits a paragraph into its runs, keeping links addressable.
 *
 * Stripping `[url]` to its label was the quiet loss: a description
 * saying "get the patch [url=…]here[/url]" rendered as "here", which
 * points at nothing. 108 of them across ten mods.
 */
function spansOf(text: string): Span[] {
  const spans: Span[] = []

  const push = (span: Span) => {
    if (span.text !== '') {
      spans.push(span)
    }
  }

  let rest = text

  while (rest !== '') {
    const match = INLINE_TAG.exec(rest)

    if (match === null || match.index === undefined) {
      push({ kind: 'text', text: plain(rest) })
      break
    }

    push({ kind: 'text', text: plain(rest.slice(0, match.index)) })

    const [whole, tag, attribute, body] = match
    const inner = plain(body)

    if (tag.toLowerCase() === 'url') {
      const href = (attribute ?? '').trim()

      // A relative or javascript: target has no business here; the text
      // survives, the link does not.
      push(
        /^https?:\/\//i.test(href)
          ? { kind: 'link', text: inner === '' ? href : inner, href }
          : { kind: 'text', text: inner },
      )
    } else if (tag.toLowerCase() === 'b') {
      push({ kind: 'strong', text: inner })
    } else {
      push({ kind: 'emphasis', text: inner })
    }

    rest = rest.slice(match.index + whole.length)
  }

  // Empty runs go first, so a caption whose picture sat at the front
  // does not keep the space the picture used to occupy.
  return trimEnds(spans.filter((span) => span.text !== ''))
}

/** Whitespace inside the sentence stays; whitespace around it goes. */
function trimEnds(spans: Span[]): Span[] {
  const trimmed = [...spans]

  while (trimmed.length > 0 && trimmed[0].text.trim() === '') {
    trimmed.shift()
  }

  while (trimmed.length > 0 && trimmed[trimmed.length - 1].text.trim() === '') {
    trimmed.pop()
  }

  if (trimmed.length === 0) {
    return []
  }

  const first = trimmed[0]
  const last = trimmed[trimmed.length - 1]

  trimmed[0] = { ...first, text: first.text.replace(/^\s+/, '') }
  trimmed[trimmed.length - 1] = {
    ...trimmed[trimmed.length - 1],
    text: last.text.replace(/\s+$/, ''),
  }

  return trimmed.filter((span) => span.text !== '')
}

/**
 * Drops any tag left over, keeping the words it wrapped.
 *
 * Deliberately does not trim: a run is a slice of a sentence, and
 * trimming each one turned "get the patch [url]here[/url] first" into
 * "get the patchherefirst". Trimming happens once, on the whole
 * paragraph.
 */
function plain(text: string): string {
  return text
    .replace(/\[\/?(?:b|i|u|strike|h[1-6]|list|quote|code|spoiler|noparse)(?:=[^\]]*)?\]/gi, '')
    .replace(/\[url=[^\]]*\]([^[]*)\[\/url\]/gi, '$1')
    .replace(/\[\*\]/g, '')
    .replace(/\[[^\]]{0,40}\]/g, '')
    .replace(/[ \t]+\n/g, '\n')
}
