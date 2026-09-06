import { BufferAttribute, BufferGeometry } from 'three'

/**
 * Reads Project Zomboid's own mesh format.
 *
 * The wheels are the reason this exists: they ship only as
 * media/models/Vehicles_Wheel.txt, not as FBX, so three.js cannot load
 * them and a vehicle would be drawn sitting on nothing.
 *
 * The format is plain text and describes itself. A header names the
 * vertex layout, then one line per stride element for every vertex,
 * then the triangles as index triples:
 *
 *     # Vertex Stride Data:
 *     0
 *     VertexArray
 *     12
 *     NormalArray
 *     24
 *     TextureCoordArray
 *     # Vertex Count:
 *     52
 *     # Vertex Buffer:
 *     0.07087200, 0.15803400, 0.00000000    <- position
 *     -0.00000020, 1.00000000, -0.00000019  <- normal
 *     0.17558320, 0.18401920                <- texture coordinate
 *     ...
 *     # Number of Faces:
 *     96
 *     # Face Data:
 *     44, 40, 45
 *
 * Read from the file rather than assumed: the stride is taken from the
 * header, so a model that carries extra arrays still lines up.
 */

/** The arrays this reader knows how to place. */
const KNOWN_ARRAYS = new Set([
  'VertexArray',
  'NormalArray',
  'TextureCoordArray',
  'BlendWeightArray',
  'BlendIndexArray',
])

export function parseZomboidMesh(text: string): BufferGeometry | null {
  const lines = text
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => line !== '')

  const layout = readLayout(lines)
  const vertexCount = readNumberAfter(lines, '# Vertex Count:')

  if (layout === null || vertexCount === null || vertexCount < 3) {
    return null
  }

  const start = lines.indexOf('# Vertex Buffer:')

  if (start === -1) {
    return null
  }

  const positions = new Float32Array(vertexCount * 3)
  const normals = new Float32Array(vertexCount * 3)
  const uvs = new Float32Array(vertexCount * 2)

  for (let vertex = 0; vertex < vertexCount; vertex += 1) {
    const base = start + 1 + vertex * layout.length

    for (const [element, kind] of layout.entries()) {
      const numbers = readNumbers(lines[base + element])

      if (numbers === null) {
        return null
      }

      if (kind === 'VertexArray' && numbers.length >= 3) {
        positions.set(numbers.slice(0, 3), vertex * 3)
      } else if (kind === 'NormalArray' && numbers.length >= 3) {
        normals.set(numbers.slice(0, 3), vertex * 3)
      } else if (kind === 'TextureCoordArray' && numbers.length >= 2) {
        // The format's v axis grows downward, three.js's upward, so it
        // is flipped here rather than leaving every texture upside down.
        uvs[vertex * 2] = numbers[0]
        uvs[vertex * 2 + 1] = 1 - numbers[1]
      }
    }
  }

  const faces = readFaces(lines, vertexCount)

  if (faces === null) {
    return null
  }

  const geometry = new BufferGeometry()
  geometry.setAttribute('position', new BufferAttribute(positions, 3))
  geometry.setAttribute('normal', new BufferAttribute(normals, 3))
  geometry.setAttribute('uv', new BufferAttribute(uvs, 2))
  geometry.setIndex(faces)

  return geometry
}

/** Which array each line of a vertex holds, in the order they appear. */
function readLayout(lines: string[]): string[] | null {
  const start = lines.indexOf('# Vertex Stride Data:')

  if (start === -1) {
    return null
  }

  const layout: string[] = []

  // Offset then name, in pairs. The header itself is followed by two
  // more comment lines describing the columns, so a comment only ends
  // the section once an entry has been read.
  for (let index = start + 1; index < lines.length; index += 1) {
    const line = lines[index]

    if (line.startsWith('#')) {
      if (layout.length > 0) {
        break
      }

      continue
    }

    if (KNOWN_ARRAYS.has(line)) {
      layout.push(line)
    } else if (!/^-?\d+$/.test(line)) {
      // Neither an offset nor an array this reader places.
      return null
    }
  }

  return layout.length === 0 ? null : layout
}

/**
 * The triangles, refused rather than clamped when an index is out of
 * range: a wrong index draws a spike across the model.
 */
function readFaces(lines: string[], vertexCount: number): number[] | null {
  const start = lines.indexOf('# Face Data:')

  if (start === -1) {
    return null
  }

  const faces: number[] = []

  for (let index = start + 1; index < lines.length; index += 1) {
    const line = lines[index]

    if (line.startsWith('#')) {
      break
    }

    const numbers = readNumbers(line)

    if (numbers === null || numbers.length !== 3) {
      return null
    }

    for (const corner of numbers) {
      if (!Number.isInteger(corner) || corner < 0 || corner >= vertexCount) {
        return null
      }

      faces.push(corner)
    }
  }

  return faces.length === 0 ? null : faces
}

function readNumbers(line: string | undefined): number[] | null {
  if (line === undefined || line.startsWith('#')) {
    return null
  }

  const numbers = line.split(',').map((part) => Number.parseFloat(part.trim()))

  return numbers.some(Number.isNaN) ? null : numbers
}

function readNumberAfter(lines: string[], marker: string): number | null {
  const at = lines.indexOf(marker)

  if (at === -1) {
    return null
  }

  const value = Number.parseInt(lines[at + 1] ?? '', 10)

  return Number.isNaN(value) ? null : value
}
