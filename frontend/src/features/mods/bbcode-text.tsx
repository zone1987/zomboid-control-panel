import { Fragment } from 'react'

import { blocksOf, type Block, type Picture as PictureShape, type Span } from './bbcode'

/**
 * A workshop description, which arrives as BBCode rather than HTML.
 *
 * Rendered from parsed blocks rather than by turning tags into markup
 * and handing that to `dangerouslySetInnerHTML`: the text is written
 * by strangers on the internet, so it is treated as data throughout
 * and anything unrecognised is shown as the plain text it is.
 */
export function BbcodeText({ text }: { text: string }) {
  const blocks = blocksOf(text)

  if (blocks.length === 0) {
    return null
  }

  return (
    <div className="space-y-3 text-sm leading-relaxed break-words">
      {blocks.map((block, index) => (
        <Fragment key={index}>{render(block)}</Fragment>
      ))}
    </div>
  )
}

/**
 * Steam's own three sizes (20/18/16px), scaled to sit inside a card.
 *
 * Note the weights go *down* as the level rises, which is what Steam
 * does: `bb_h3` is lighter than body text rather than heavier.
 */
const HEADING: Record<number, string> = {
  1: 'text-base font-semibold text-foreground',
  2: 'text-[0.9375rem] font-medium text-foreground',
  3: 'font-medium text-foreground',
}

function render(block: Block) {
  switch (block.kind) {
    case 'heading':
      // A `<p>`, never an `<h2>`: the heading hierarchy belongs to the
      // panel, and text written by a mod author must not insert itself
      // into the outline a screen reader navigates by.
      //
      // Three sizes because Steam has three (20/18/16px), scaled down
      // to sit inside a card rather than to head a page.
      return (
        <p className={HEADING[Math.min(block.level, 3)] ?? HEADING[3]}>
          <Spans spans={block.spans} />
        </p>
      )

    case 'list':
      return (
        <ul className="list-disc space-y-1 pl-5 text-muted-foreground">
          {block.items.map((item, index) => (
            <li key={index}>
              <Spans spans={item} />
            </li>
          ))}
        </ul>
      )

    case 'quote':
      return (
        <blockquote className="border-l-2 border-muted pl-3 text-muted-foreground italic">
          <Spans spans={block.spans} />
        </blockquote>
      )

    case 'code':
      return (
        <pre className="overflow-x-auto rounded-md bg-muted p-3 font-mono text-xs">
          {block.text}
        </pre>
      )

    case 'pictures':
      return (
        <div className="space-y-2">
          <div className="flex flex-wrap items-start gap-2">
            {block.pictures.map((picture) => (
              <Picture key={picture.src} picture={picture} />
            ))}
          </div>

          {block.caption.length > 0 && (
            <p className="text-muted-foreground">
              <Spans spans={block.caption} />
            </p>
          )}
        </div>
      )

    case 'text':
      // A single newline is meaningful here: Steam writes the workshop
      // id and the mod id on their own lines, and collapsing it runs
      // the two together.
      return (
        <p className="whitespace-pre-line text-muted-foreground">
          <Spans spans={block.spans} />
        </p>
      )
  }
}

function Spans({ spans }: { spans: Span[] }) {
  return (
    <>
      {spans.map((span, index) => {
        switch (span.kind) {
          case 'strong':
            return (
              <strong key={index} className="font-semibold text-foreground">
                {span.text}
              </strong>
            )

          case 'emphasis':
            return (
              <em key={index} className="italic">
                {span.text}
              </em>
            )

          case 'link':
            return (
              <a
                key={index}
                href={span.href}
                target="_blank"
                rel="noreferrer noopener"
                className="text-primary underline-offset-2 hover:underline"
              >
                {span.text}
              </a>
            )

          case 'text':
            return <Fragment key={index}>{span.text}</Fragment>
        }
      })}
    </>
  )
}

function Picture({ picture }: { picture: PictureShape }) {
  const image = (
    <img
      src={picture.src}
      alt=""
      loading="lazy"
      decoding="async"
      // A decorative banner does not need to tell its host which panel
      // page somebody is reading.
      referrerPolicy="no-referrer"
      className="max-h-64 max-w-full rounded-md object-contain"
      // A host that has gone away leaves a broken frame otherwise, and
      // the description then reads as damaged rather than as one dead
      // link.
      onError={(event) => {
        event.currentTarget.style.display = 'none'
      }}
    />
  )

  return picture.href === null ? (
    image
  ) : (
    // A box of its own, not `display: contents`: without one the flex
    // row cannot lay the link out and the pictures pile on top of each
    // other with the prose running through them.
    <a
      href={picture.href}
      target="_blank"
      rel="noreferrer noopener"
      className="block max-w-full shrink-0"
    >
      {image}
    </a>
  )
}
