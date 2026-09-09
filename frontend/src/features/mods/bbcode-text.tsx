import { Fragment } from 'react'

/**
 * A workshop description, which arrives as BBCode rather than HTML.
 *
 * Rendered by parsing rather than by turning tags into markup and
 * handing that to `dangerouslySetInnerHTML`: the text is written by
 * strangers on the internet, so it is treated as data throughout and
 * anything unrecognised is shown as the plain text it is.
 *
 * Deliberately narrow. Images and videos inside a description point at
 * hosts the CSP does not allow, so they would draw nothing but a
 * broken frame; their text stands in for them instead.
 */
export function BbcodeText({ text }: { text: string }) {
  if (text.trim() === '') {
    return null
  }

  return (
    <div className="space-y-2 text-sm leading-relaxed break-words">
      {blocksOf(text).map((block, index) => (
        <Fragment key={index}>{block}</Fragment>
      ))}
    </div>
  )
}

function blocksOf(text: string) {
  const cleaned = text
    // Steam sends CRLF, so a paragraph split on \n{2,} alone matched
    // nothing and the whole description collapsed into one block.
    .replace(/\r\n/g, '\n')
    // Images and embeds cannot load under `img-src 'self'`, so the
    // markers go rather than leaving [img] literals in the prose.
    .replace(/\[img\][^[]*\[\/img\]/gi, '')
    .replace(/\[previewyoutube[^\]]*\][^[]*\[\/previewyoutube\]/gi, '')
    .replace(/\[\/?(?:table|tr|td|th)\]/gi, '\n')

  return cleaned
    .split(/\n{2,}/)
    .map((paragraph) => paragraph.trim())
    .filter((paragraph) => paragraph !== '')
    .map((paragraph, index) => {
      const heading = /^\[h(\d)\](.*?)\[\/h\1\]$/is.exec(paragraph)

      if (heading !== null) {
        return (
          <p key={index} className="font-semibold text-foreground">
            {inline(heading[2])}
          </p>
        )
      }

      if (/\[list\]/i.test(paragraph)) {
        const items = [...paragraph.matchAll(/\[\*\]\s*([^[]*)/g)].map(([, item]) => item.trim())

        if (items.length > 0) {
          return (
            <ul key={index} className="list-disc space-y-1 pl-5">
              {items.map((item, itemIndex) => (
                <li key={itemIndex}>{inline(item)}</li>
              ))}
            </ul>
          )
        }
      }

      return (
        <p key={index} className="text-muted-foreground">
          {inline(paragraph)}
        </p>
      )
    })
}

/**
 * Strips the inline tags and keeps their text.
 *
 * Bold and italics are dropped rather than rendered: a workshop
 * description often shouts in bold for whole paragraphs, and carrying
 * that into the panel would fight the interface's own hierarchy.
 */
function inline(text: string) {
  return text
    .replace(/\[url=[^\]]*\]([^[]*)\[\/url\]/gi, '$1')
    .replace(/\[\/?(?:b|i|u|strike|h\d|list|quote|code|spoiler|noparse)\]/gi, '')
    .replace(/\[\*\]/g, '')
    .replace(/\[[^\]]{0,40}\]/g, '')
    .trim()
}
