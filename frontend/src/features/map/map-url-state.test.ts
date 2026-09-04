import { describe, expect, it } from 'vitest'

import { decodeViewState, encodeViewState, isSameView } from './map-url-state'

describe('encoding a view into a link', () => {
  it('writes x, y and zoom', () => {
    expect(encodeViewState({ x: 10778, y: 9770, zoom: 4.5, floor: 0 })).toBe('10778,9770,4.5')
  })

  /** Most links point at the ground, so saying so adds nothing. */
  it('leaves the ground floor out', () => {
    expect(encodeViewState({ x: 100, y: 200, zoom: 1, floor: 0 })).toBe('100,200,1')
  })

  it('includes a floor above the ground', () => {
    expect(encodeViewState({ x: 100, y: 200, zoom: 1, floor: 3 })).toBe('100,200,1,3')
  })

  it('includes a basement', () => {
    expect(encodeViewState({ x: 100, y: 200, zoom: 1, floor: -1 })).toBe('100,200,1,-1')
  })

  it('rounds the coordinates to whole squares', () => {
    expect(encodeViewState({ x: 10778.7, y: 9770.2, zoom: 2, floor: 0 })).toBe('10779,9770,2')
  })
})

describe('decoding a link', () => {
  it('reads back what it wrote', () => {
    const state = { x: 10778, y: 9770, zoom: 4.5, floor: 2 }

    expect(decodeViewState(encodeViewState(state))).toEqual(state)
  })

  it('tolerates the leading hash', () => {
    expect(decodeViewState('#100,200,3')).toEqual({ x: 100, y: 200, zoom: 3, floor: 0 })
  })

  /** So a link from another Zomboid map lands somewhere sensible. */
  it('accepts the x-separated form other maps use', () => {
    expect(decodeViewState('#12574x4415x44')).toEqual({
      x: 12574,
      y: 4415,
      zoom: 44,
      floor: 0,
    })
  })

  it('defaults the zoom when only a position is given', () => {
    expect(decodeViewState('100,200')).toEqual({ x: 100, y: 200, zoom: 1, floor: 0 })
  })

  it('is nothing for an empty hash', () => {
    expect(decodeViewState('')).toBeNull()
    expect(decodeViewState('#')).toBeNull()
  })

  it('is nothing for something that is not a position', () => {
    expect(decodeViewState('#muldraugh')).toBeNull()
    expect(decodeViewState('#100')).toBeNull()
  })

  it('truncates a fractional floor rather than rounding it', () => {
    expect(decodeViewState('100,200,1,2.9')?.floor).toBe(2)
  })
})

describe('deciding whether the URL needs writing', () => {
  const view = { x: 100, y: 200, zoom: 3, floor: 0 }

  it('is the same view when nothing moved', () => {
    expect(isSameView(view, { ...view })).toBe(true)
  })

  /** Sub-square movement is not worth a history entry. */
  it('is the same view for a fraction of a square', () => {
    expect(isSameView(view, { ...view, x: 100.4 })).toBe(true)
  })

  it('is a different view once a whole square has passed', () => {
    expect(isSameView(view, { ...view, x: 102 })).toBe(false)
  })

  it('is a different view on another floor', () => {
    expect(isSameView(view, { ...view, floor: 1 })).toBe(false)
  })

  it('is a different view after a real zoom change', () => {
    expect(isSameView(view, { ...view, zoom: 3.5 })).toBe(false)
  })

  it('is never the same as no view at all', () => {
    expect(isSameView(null, view)).toBe(false)
  })
})
