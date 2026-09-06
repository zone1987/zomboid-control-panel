import type { MapVehicle } from './map'
import type { RenderedVehicle } from './vehicle-renderer'

/**
 * How a vehicle looks on the map.
 *
 * Drawn as a shape rather than an icon so it can carry three things at
 * once: which way the vehicle faces, what colour it was painted, and
 * whether it is wrecked. The paint is only known for vehicles the
 * bridge can see; the rest fall back to a neutral body.
 */

/**
 * How large a vehicle is, in world squares, when the game's own figure
 * is not to hand.
 *
 * The catalogue carries the real extents for every vehicle the game
 * declares; this covers a mod's own, which is the only case left.
 */
const FALLBACK_SIZE = { length: 4.8, width: 2.1 }

/** The size a vehicle occupies on the map, in world squares. */
export function vehicleSize(
  declared?: { length: number; width: number } | null,
): { length: number; width: number } {
  if (declared != null && declared.length > 0 && declared.width > 0) {
    return declared
  }

  return FALLBACK_SIZE
}

export function vehicleClass(vehicle: MapVehicle): string {
  const classes = ['pz-vehicle']

  if (vehicle.engineRunning) {
    classes.push('pz-vehicle--running')
  }

  if (vehicle.condition?.wrecked === true || /burnt|smashed|wreck/i.test(vehicle.script)) {
    classes.push('pz-vehicle--wrecked')
  } else if (vehicle.condition?.damaged === true) {
    classes.push('pz-vehicle--damaged')
  }

  if (vehicle.live !== true) {
    classes.push('pz-vehicle--stored')
  }

  return classes.join(' ')
}

/**
 * The body, rotated to the way the vehicle faces.
 *
 * An SVG rather than a rotated div: the nose has to be visible for the
 * heading to mean anything, and that needs a shape rather than a box.
 */
export function vehicleShape(
  vehicle: MapVehicle,
  declared?: { length: number; width: number } | null,
): SVGSVGElement {
  const { length, width } = vehicleSize(declared)
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg')

  // Fills whatever box the map gives it, so it scales with the view.
  svg.setAttribute('viewBox', `0 0 ${width} ${length}`)
  svg.setAttribute('preserveAspectRatio', 'xMidYMid meet')
  svg.setAttribute('aria-hidden', 'true')
  svg.classList.add('pz-vehicle__body')

  // A flat rotation, which is an approximation: the map's ground plane
  // is squashed, so a turning vehicle really traces an ellipse. The
  // rendered model does that properly; this stands in until it loads.
  if (vehicle.heading !== null) {
    svg.style.transform = `rotate(${vehicle.heading}deg)`
  }

  const body = document.createElementNS('http://www.w3.org/2000/svg', 'path')
  const nose = Math.max(2, Math.round(length * 0.22))

  // A rectangle with one tapered end: the taper is the front.
  body.setAttribute(
    'd',
    [
      `M ${width / 2} 0`,
      `L ${width} ${nose}`,
      `L ${width} ${length - 1}`,
      `Q ${width} ${length} ${width - 1} ${length}`,
      `L 1 ${length}`,
      `Q 0 ${length} 0 ${length - 1}`,
      `L 0 ${nose}`,
      'Z',
    ].join(' '),
  )
  body.setAttribute('fill', paintOf(vehicle))
  body.setAttribute('class', 'pz-vehicle__paint')

  svg.append(body)

  return svg
}

/**
 * The colour the vehicle was painted, as the game stores it.
 *
 * Hue, saturation and value are floats from 0 to 1. The game halves the
 * saturation before it renders (Color.HSBtoRGB(hue, sat * 0.5, val)),
 * so the same is done here or every car comes out lurid.
 */
export function paintOf(vehicle: MapVehicle): string {
  const { hue, saturation, value } = vehicle

  if (typeof hue !== 'number' || typeof saturation !== 'number' || typeof value !== 'number') {
    return 'var(--pz-vehicle-unknown)'
  }

  const percent = (fraction: number) => `${Math.round(fraction * 100)}%`

  // HSL is not HSV, but for a marker a few pixels across the difference
  // is invisible, and it needs no conversion arithmetic to get wrong.
  return `hsl(${Math.round(hue * 360)} ${percent(saturation * 0.5)} ${percent(value * 0.5)})`
}

/** What the tooltip says, which is everything the panel knows. */
export function describeVehicle(
  vehicle: MapVehicle,
  name: (script: string) => string,
): string {
  const parts = [name(vehicle.script)]

  if (typeof vehicle.fuel === 'number') {
    parts.push(`${Math.round(vehicle.fuel)}%`)
  }

  if (vehicle.condition?.damaged === true) {
    parts.push(`${Math.round(vehicle.condition.intact * 100)}%`)
  }

  return parts.join(' · ')
}

/**
 * The vehicle's own rendered model.
 *
 * Already facing the right way: the turn happens in the render, because
 * the map's ground plane is squashed and a flat rotation would turn the
 * vehicle on a circle rather than on that ellipse.
 *
 * Preferred over the drawn shape wherever the artwork was uploaded: it
 * is the actual vehicle rather than a stand-in for it.
 */
export function vehicleImage(made: RenderedVehicle): HTMLImageElement {
  const image = document.createElement('img')

  image.src = made.url
  image.alt = ''
  image.draggable = false
  image.className = 'pz-vehicle__model'

  return image
}
