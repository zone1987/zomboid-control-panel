import type { MapGeometry, MapSource } from './map-config'

export type WorldPoint = { x: number; y: number }
export type ImagePoint = { x: number; y: number }
/** OpenSeadragon's own space: x in 0..1 across the image, y to scale. */
export type ViewportPoint = { x: number; y: number }

/**
 * Turns Zomboid world squares into pixels of the rendered map.
 *
 * Two projections, and they are not variations of each other.
 *
 * Top-down is a straight scale: the game's own map is one pixel per
 * square, so the numbers pass through untouched.
 *
 * Isometric rotates the grid 45 degrees and halves the vertical axis,
 * which is what makes walls and roofs visible. A floor is drawn higher
 * up the image than the one below it, so the floor is part of the
 * transform rather than a separate offset applied afterwards.
 */
export function worldToImage(point: WorldPoint, floor: number, source: MapSource): ImagePoint {
  const { originX, originY, squareSize, scale, floorHeight } = source.geometry

  if (source.projection === 'topDown') {
    return {
      x: (originX + point.x * squareSize) / scale,
      y: (originY + point.y * squareSize) / scale,
    }
  }

  return {
    x: (originX + (point.x - point.y) * squareSize * 0.5) / scale,
    y: (originY + (point.x + point.y) * squareSize * 0.25 - floor * floorHeight) / scale,
  }
}

/** The way back, for turning a click into a world coordinate. */
export function imageToWorld(point: ImagePoint, floor: number, source: MapSource): WorldPoint {
  const { originX, originY, squareSize, scale, floorHeight } = source.geometry

  if (source.projection === 'topDown') {
    return {
      x: (point.x * scale - originX) / squareSize,
      y: (point.y * scale - originY) / squareSize,
    }
  }

  // Undo the rotation: the two isometric axes are sum and difference of
  // the world axes, so solving for x and y is a pair of linear terms.
  const dx = (point.x * scale - originX) / (squareSize * 0.5)
  const dy = (point.y * scale - originY + floor * floorHeight) / (squareSize * 0.25)

  return { x: (dy + dx) / 2, y: (dy - dx) / 2 }
}

/**
 * OpenSeadragon measures its viewport in image widths: x runs 0..1 from
 * left to right, and y uses the same unit, so it ends at height/width.
 */
export function imageToViewport(point: ImagePoint, geometry: MapGeometry): ViewportPoint {
  return { x: point.x / geometry.width, y: point.y / geometry.width }
}

export function viewportToImage(point: ViewportPoint, geometry: MapGeometry): ImagePoint {
  return { x: point.x * geometry.width, y: point.y * geometry.width }
}

export function worldToViewport(point: WorldPoint, floor: number, source: MapSource): ViewportPoint {
  return imageToViewport(worldToImage(point, floor, source), source.geometry)
}

export function viewportToWorld(point: ViewportPoint, floor: number, source: MapSource): WorldPoint {
  return imageToWorld(viewportToImage(point, source.geometry), floor, source)
}

/** Rounded to whole squares, which is the only precision the game has. */
export function roundToSquare(point: WorldPoint): WorldPoint {
  return { x: Math.round(point.x), y: Math.round(point.y) }
}

export function isInsideWorld(point: WorldPoint, source: MapSource): boolean {
  const image = worldToImage(point, 0, source)

  return (
    image.x >= 0 &&
    image.y >= 0 &&
    image.x <= source.geometry.width &&
    image.y <= source.geometry.height
  )
}
