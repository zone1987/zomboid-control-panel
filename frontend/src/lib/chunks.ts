/**
 * Splitting a file into requests a container will accept.
 *
 * PHP refuses a body above `post_max_size` at startup, before any
 * application code runs, and answers HTML rather than a reason -- so a
 * 52 MB pack arrived as an unexplained 500. Pieces avoid that, and a
 * dropped connection then costs one piece instead of the upload.
 */

/** Kept under the request size the container accepts. */
export const CHUNK_BYTES = 8 * 1024 * 1024

/**
 * The offsets a file of this size is sent at.
 *
 * Pure on purpose: an off-by-one here is a piece that never arrives,
 * and that is worth testing without a network.
 */
export function chunkOffsets(size: number, chunkBytes = CHUNK_BYTES): number[] {
  if (size <= 0) {
    return [0]
  }

  const offsets: number[] = []

  for (let offset = 0; offset < size; offset += chunkBytes) {
    offsets.push(offset)
  }

  return offsets
}

/** How much has arrived once the piece at this offset is through. */
export function sentAfter(offset: number, size: number, chunkBytes = CHUNK_BYTES): number {
  return Math.min(offset + chunkBytes, size)
}
