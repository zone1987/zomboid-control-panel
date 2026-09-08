import { describe, expect, it } from 'vitest'

import { CHUNK_BYTES, chunkOffsets, sentAfter } from './chunks'
import { MODEL_CHUNK_BYTES, needsChunking } from '@/features/settings/vehicle-models'

/**
 * Splitting an upload into requests the container accepts.
 *
 * UI2.pack is 52 MB against a `post_max_size` of 16 MB, and PHP
 * refuses an oversized body at startup -- before any application code
 * runs, answering HTML. So the interface showed an unexplained 500 and
 * the largest icon pack could not be uploaded at all.
 */
describe('splitting a file into pieces', () => {
  it('splits a file larger than one request', () => {
    expect(chunkOffsets(CHUNK_BYTES * 2 + 1024)).toEqual([
      0,
      CHUNK_BYTES,
      CHUNK_BYTES * 2,
    ])
  })

  it('sends a small file in a single piece', () => {
    expect(chunkOffsets(1024)).toEqual([0])
  })

  it('sends a file of exactly one piece once', () => {
    expect(chunkOffsets(CHUNK_BYTES)).toEqual([0])
  })

  /** An empty file still needs one request, or nothing is ever finished. */
  it('still makes one request for an empty file', () => {
    expect(chunkOffsets(0)).toEqual([0])
  })

  it('leaves no gap and no repeat between pieces', () => {
    const size = CHUNK_BYTES * 3 + 7

    for (const [index, offset] of chunkOffsets(size).entries()) {
      expect(offset).toBe(index * CHUNK_BYTES)
    }
  })

  it('reports the whole file as sent after the last piece', () => {
    const size = CHUNK_BYTES * 3 + 7
    const offsets = chunkOffsets(size)

    expect(sentAfter(offsets.at(-1) ?? 0, size)).toBe(size)
    expect(sentAfter(0, CHUNK_BYTES * 2)).toBe(CHUNK_BYTES)
  })

  /** Every piece has to fit the request the container accepts. */
  it('keeps every piece within the request limit', () => {
    const size = CHUNK_BYTES * 4 + 123

    for (const offset of chunkOffsets(size)) {
      expect(sentAfter(offset, size) - offset).toBeLessThanOrEqual(CHUNK_BYTES)
    }
  })

  it('honours a chunk size the caller chooses', () => {
    expect(chunkOffsets(2500, 1000)).toEqual([0, 1000, 2000])
    expect(sentAfter(2000, 2500, 1000)).toBe(2500)
  })
})

/**
 * Vehicle models are small in the base game -- the largest is under a
 * megabyte -- so this guards the case a mod introduces rather than one
 * that exists today.
 */
describe('which vehicle models travel alone', () => {
  it('batches a model of ordinary size', () => {
    expect(needsChunking({ size: 764 * 1024 })).toBe(false)
  })

  it('sends an oversized model in pieces', () => {
    expect(needsChunking({ size: MODEL_CHUNK_BYTES + 1 })).toBe(true)
  })

  /** Exactly the limit still fits one request. */
  it('keeps a model of exactly the limit in a batch', () => {
    expect(needsChunking({ size: MODEL_CHUNK_BYTES })).toBe(false)
  })
})
