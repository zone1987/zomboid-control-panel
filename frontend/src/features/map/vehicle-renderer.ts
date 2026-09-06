import {
  AmbientLight,
  Box3,
  BufferGeometry,
  Color,
  DirectionalLight,
  Group,
  LoadingManager,
  Mesh,
  Object3D,
  MeshBasicMaterial,
  MeshLambertMaterial,
  PlaneGeometry,
  OrthographicCamera,
  Scene,
  Texture,
  TextureLoader,
  Vector3,
  WebGLRenderer,
  type Material,
} from 'three'
import { FBXLoader } from 'three/examples/jsm/loaders/FBXLoader.js'

import type { MapVehicle } from './map'
import { parseZomboidMesh } from './zomboid-mesh'

/**
 * Draws Zomboid's own vehicle models to images the map can place.
 *
 * Rendered once per vehicle type and paint colour, then reused: the map
 * holds a hundred vehicles and repaints on every pan, while a rendered
 * body only changes when the vehicle does. The heading is applied as a
 * CSS rotation on the finished image rather than in the scene, so one
 * render serves a type in every direction.
 *
 * One WebGLRenderer for all of them. A context per vehicle exhausts the
 * browser's limit -- around sixteen -- and starts silently losing the
 * oldest.
 */

export type VehicleWheel = {
  x: number
  y: number
  z: number
  radius: number
  width: number
}

export type VehicleArtwork = {
  model: string
  texture: string | null
  mask: string | null
  scale: number
  /** The game's own extents, in world squares. */
  length: number
  width: number
  wheelMesh: string | null
  wheelTexture: string | null
  wheels: VehicleWheel[]
}

/** Per-render choices that do not belong to the vehicle itself. */
export type DrawOptions = {
  /**
   * Draw a patch of road under the vehicle.
   *
   * For the catalogue preview, where a vehicle on nothing reads as
   * floating. The map does not want it: the map is the ground.
   */
  ground?: boolean
}

/** What the map needs to draw one vehicle. */
export type RenderedVehicle = {
  url: string
  width: number
  height: number
}

/**
 * Rendered at the full resolution of the shell textures.
 *
 * A five-metre car spans about 250 screen pixels at the closest useful
 * zoom, and anything smaller than that is enlarged and looks smeared.
 * The textures are 512 square, so this is all the detail there is --
 * going further would invent none.
 *
 * Affordable because each is drawn once and kept: see cacheKey.
 */
const RENDER_SIZE = 512

/**
 * How finely the heading is bucketed for the cache.
 *
 * Every distinct bucket is a render of its own, so this trades memory
 * for smoothness: at fifteen degrees a vehicle is one of 24 images,
 * and half a degree of error is invisible at map size. Five degrees
 * would triple the cache for no visible gain.
 */
const HEADING_STEP_DEGREES = 15

/**
 * The loaded bodies are in centimetres while the scripts speak metres.
 *
 * FBXLoader hands back Vehicles_PickUpVan spanning 282 along its
 * length, and the same vehicle's extents call it 4.46 -- so the model
 * is a hundred times the script's unit.
 */
const CENTIMETRES_PER_METRE = 100

/** Stands in for a texture the model asks for and the panel does not serve. */
const TRANSPARENT_PIXEL =
  'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'

export class VehicleRenderer {
  private renderer: WebGLRenderer | null = null

  private readonly models = new Map<string, Promise<Group | null>>()

  private readonly textures = new Map<string, Promise<Texture | null>>()

  private readonly meshes = new Map<string, Promise<BufferGeometry | null>>()

  /** Keyed by type and paint, because that is what changes the picture. */
  private readonly rendered = new Map<string, Promise<RenderedVehicle | null>>()

  private readonly fileUrl: (name: string) => string

  private readonly artworkFor: (script: string) => VehicleArtwork | null

  constructor(
    fileUrl: (name: string) => string,
    artworkFor: (script: string) => VehicleArtwork | null,
  ) {
    this.fileUrl = fileUrl
    this.artworkFor = artworkFor
  }

  /**
   * Null when this vehicle cannot be drawn -- no model uploaded, or no
   * WebGL. The caller falls back to a plain marker rather than showing
   * a gap.
   */
  draw(vehicle: MapVehicle, options: DrawOptions = {}): Promise<RenderedVehicle | null> {
    const artwork = this.artworkFor(vehicle.script)

    // Without its shell texture a model renders as a white block, which
    // reads as a fault rather than as a missing file. The drawn shape
    // is the better answer until the texture is uploaded.
    if (artwork === null || artwork.texture === null) {
      return Promise.resolve(null)
    }

    const key = cacheKey(vehicle, artwork, options)
    const known = this.rendered.get(key)

    if (known !== undefined) {
      return known
    }

    const pending = this.render(vehicle, artwork, options).catch(() => null)
    this.rendered.set(key, pending)

    return pending
  }

  /** What the game declares for this vehicle, or null for a mod's own. */
  artwork(script: string): VehicleArtwork | null {
    return this.artworkFor(script)
  }

  /** Frees the GL context and every image made from it. */
  dispose(): void {
    for (const pending of this.rendered.values()) {
      void pending.then((made) => {
        if (made !== null) {
          URL.revokeObjectURL(made.url)
        }
      })
    }

    this.rendered.clear()
    this.models.clear()
    this.textures.clear()
    this.meshes.clear()
    this.renderer?.dispose()
    this.renderer = null
  }

  private async render(
    vehicle: MapVehicle,
    artwork: VehicleArtwork,
    options: DrawOptions = {},
  ): Promise<RenderedVehicle | null> {
    const [model, renderer] = await Promise.all([this.model(artwork.model), this.gl()])

    if (model === null || renderer === null) {
      return null
    }

    const body = model.clone(true)
    await this.paint(body, vehicle, artwork)

    // Wheels are a mesh of their own -- the game ships them only in its
    // own text format -- placed at the offsets the vehicle's script
    // declares.
    //
    // Added to the node that holds the body geometry, not to the group
    // around it. FBXLoader puts a -90 degree turn about x on that node
    // to bring the file's z-up authoring into three.js's y-up, and a
    // wheel hung outside it keeps the file's axes while the body has
    // already been turned -- which is why they sat in the wrong place
    // however the offsets were scaled.
    const frame = bodyFrame(body)

    for (const wheel of await this.wheelsFor(artwork)) {
      frame.add(wheel)
    }

    // The models are authored z-up and y-forward, whatever their FBX
    // header claims: the raw vertices of Vehicles_PickUpVan span 282
    // along y and 88 along z, and a pickup is longer than it is tall.
    //
    // Turned a quarter circle about x to stand it up, roof upward. That
    // puts the model's own front -- +y, where its front wheels sit --
    // onto -z, which the heading offset accounts for.
    // No axis fixing: the loader already converts the file's z-up
    // authoring to three.js's y-up, so after loading the length is on
    // z and the height on y -- measured, 122 x 88 x 282 for the pickup.
    // An extra quarter turn here laid every vehicle back down.
    const upright = new Group()
    upright.add(body)

    // The heading is turned here, in the scene, rather than as a CSS
    // rotation on the finished image.
    //
    // The map's ground plane is a circle squashed to half its height,
    // so a vehicle turning through a full circle traces an ellipse on
    // screen: equal steps of heading are unequal steps on the image --
    // 0, 45, 90 degrees of heading land at 63.4, 90, 116.6. A flat
    // rotation turns on a circle and cannot express that.
    const turned = new Group()
    turned.add(upright)
    // The bucketed value, not the raw one: the cache is keyed by the
    // bucket, so rendering the exact heading would hand the next
    // vehicle in that bucket a picture turned slightly wrong.
    turned.rotation.y = degrees(bucketedHeading(vehicle))

    // The map is the 2:1 projection 2D games use: the ground plane is
    // squashed to half, but height is drawn at full scale. A camera
    // cannot do both at once, so the body is stretched upward by
    // exactly what the tilt takes away.
    turned.scale.y = HEIGHT_CORRECTION

    const scene = new Scene()
    scene.add(new AmbientLight(0xffffff, 1.6))

    // From above and slightly to one side, so a roof does not read as a
    // flat rectangle.
    const sun = new DirectionalLight(0xffffff, 1.1)
    sun.position.set(-0.4, 1, 0.35)
    scene.add(sun)
    scene.add(turned)

    const camera = isometricCamera(turned, options.ground === true ? SCENERY_MARGIN : 1)

    // After the camera: the tiles are placed in its own plane.
    if (options.ground === true) {
      const [street, grass, bush] = await Promise.all([
        this.texture(FLOOR_STREET),
        this.texture(FLOOR_GRASS),
        this.texture(SCENERY_BUSH),
      ])

      for (const piece of sceneryUnder(turned, street, grass, bush, camera)) {
        scene.add(piece)
      }
    }

    const canvas = renderer.domElement

    renderer.setSize(RENDER_SIZE, RENDER_SIZE, false)
    renderer.setClearColor(0x000000, 0)
    renderer.clear()
    renderer.render(scene, camera)

    const blob = await new Promise<Blob | null>((resolve) => {
      canvas.toBlob(resolve, 'image/png')
    })

    disposeGroup(body)
    upright.clear()
    turned.clear()

    if (blob === null) {
      return null
    }

    return { url: URL.createObjectURL(blob), width: RENDER_SIZE, height: RENDER_SIZE }
  }

  /**
   * Puts the shell texture on the body and tints the painted area.
   *
   * The game renders the shell texture and tints only where the mask
   * marks bodywork, which is why a car's windows and tyres keep their
   * own colour. Without a mask -- a burnt-out shell -- nothing is
   * tinted.
   */
  private async paint(
    body: Group,
    vehicle: MapVehicle,
    artwork: VehicleArtwork,
  ): Promise<void> {
    const texture = artwork.texture === null ? null : await this.texture(artwork.texture)
    const tint = tintOf(vehicle, artwork)

    if (texture === null) {
      throw new Error('The shell texture did not load.')
    }

    body.traverse((node) => {
      if (!(node instanceof Mesh)) {
        return
      }

      // Lit, unlike a flat marker: at this tilt the roof and the
      // flanks are both visible, and shading is what tells them apart.
      const material = new MeshLambertMaterial({ transparent: true })

      if (texture !== null) {
        material.map = texture
      }

      if (tint !== null) {
        material.color = tint
      }

      node.material = material
    })
  }

  /**
   * One mesh per wheel the script declares, at its own offset.
   *
   * Empty when the wheel mesh was never uploaded, which leaves a
   * vehicle sitting on nothing rather than failing to draw at all.
   */
  private async wheelsFor(artwork: VehicleArtwork): Promise<Mesh[]> {
    if (artwork.wheelMesh === null || artwork.wheels.length === 0) {
      return []
    }

    const [geometry, texture] = await Promise.all([
      this.mesh(artwork.wheelMesh),
      artwork.wheelTexture === null ? null : this.texture(artwork.wheelTexture),
    ])

    if (geometry === null) {
      return []
    }

    return artwork.wheels.map((wheel) => {
      const material = new MeshLambertMaterial({ color: 0x2b2b2b })

      if (texture !== null) {
        material.map = texture
        material.color = new Color(0xffffff)
      }

      const mesh = new Mesh(geometry, material)

      // Placed exactly as BaseVehicle.updateTransform() does it, read
      // from the decompiled source rather than guessed at:
      //
      //   matrix4f.translation(offset.x / scale * -1.0f,
      //                        offset.y / scale,
      //                        offset.z / scale)
      //
      // Two things there that four attempts by eye all missed. The x is
      // NEGATED -- the model's own x axis runs opposite to the script's,
      // so without it every wheel sits on the side of the car that has
      // no arch. And the divide by scale cancels the multiply that
      // VehicleScript.Loaded() already applied to these offsets at load
      // time, which an external reader never sees: a file's offset is
      // used as it stands.
      mesh.position.set(
        -wheel.x * CENTIMETRES_PER_METRE,
        wheel.y * CENTIMETRES_PER_METRE,
        wheel.z * CENTIMETRES_PER_METRE,
      )

      // The mesh keeps its own size.
      //
      // A script's `radius` and `width` are not used for drawing at all:
      // BaseVehicle.updateTransform() never reads them, and their only
      // other uses are a ground-clearance test, a collision distance and
      // a debug wireframe. They are physics metadata, handed to Bullet
      // by script name. Scaling the mesh to them made monster-truck
      // wheels; the mesh is already the right size, and only the unit
      // change to the body's centimetres is needed.
      mesh.scale.setScalar(CENTIMETRES_PER_METRE)

      return mesh
    })
  }

  /** The game's own text mesh format, which the wheels ship in. */
  private mesh(name: string): Promise<BufferGeometry | null> {
    const known = this.meshes.get(name)

    if (known !== undefined) {
      return known
    }

    const pending = fetch(this.fileUrl(name))
      .then((answer) => (answer.ok ? answer.text() : null))
      .then((text) => (text === null ? null : parseZomboidMesh(text)))
      .catch(() => null)

    this.meshes.set(name, pending)

    return pending
  }

  /** The body, as the FBX loader hands it over. */
  private model(name: string): Promise<Group | null> {
    const known = this.models.get(name)

    if (known !== undefined) {
      return known
    }

    // The FBX files carry their author's own texture paths
    // ("D:\\Dropbox\\...\\Vehicle_CarNormalShell.png"), and the loader
    // turns each into a request that can only 404. The shell texture is
    // set from the vehicle script instead, so those are cut off by
    // handing the loader a manager that resolves them to nothing.
    const quiet = new LoadingManager()
    quiet.setURLModifier((url) =>
      url.toLowerCase().endsWith('.fbx') ? url : TRANSPARENT_PIXEL,
    )

    const pending = new FBXLoader(quiet)
      .loadAsync(this.fileUrl(name))
      .then((group) => group as unknown as Group)
      .catch(() => null)

    this.models.set(name, pending)

    return pending
  }

  private texture(name: string): Promise<Texture | null> {
    const known = this.textures.get(name)

    if (known !== undefined) {
      return known
    }

    const pending = new TextureLoader()
      .loadAsync(this.fileUrl(name))
      .catch(() => null)

    this.textures.set(name, pending)

    return pending
  }

  /** Null where WebGL is unavailable, which is a fallback and not a fault. */
  private gl(): Promise<WebGLRenderer | null> {
    if (this.renderer !== null) {
      return Promise.resolve(this.renderer)
    }

    try {
      this.renderer = new WebGLRenderer({
        alpha: true,
        antialias: true,
        preserveDrawingBuffer: true,
      })

      return Promise.resolve(this.renderer)
    } catch {
      return Promise.resolve(null)
    }
  }
}

/**
 * The same view the map itself is drawn in.
 *
 * The map's transform sends one world square to (64, 32) pixels, so the
 * vertical axis is halved -- a 2:1 ratio, which is an elevation of
 * asin(0.5) = 30 degrees. The grid is turned 45 degrees on top of that.
 * A vehicle rendered straight down would lie flat on a world drawn at
 * an angle.
 *
 * Orthographic, because a perspective camera makes a body lean
 * differently depending on where it sits in the frame, and each of
 * these is rendered alone in the middle of its own image.
 */
export const ELEVATION_DEGREES = 30

/**
 * Undoes the tilt's foreshortening of height.
 *
 * The map's own tiles draw a metre of height as 64 pixels, the same as a
 * metre along the ground -- verified from its geometry, where a storey
 * is 192 pixels and a Zomboid storey is three metres. A camera tilted
 * to squash the ground by half also squashes height by cos(30), so the
 * model is stretched by the inverse to put it back.
 */
export const HEIGHT_CORRECTION = 1 / Math.cos((ELEVATION_DEGREES * Math.PI) / 180)

/**
 * Turned so the render already stands the way the map draws a heading of
 * zero, which is what lets the marker turn by the bare heading.
 *
 * At this yaw and tilt the model's forward axis lands 243.4 degrees
 * clockwise from the top of the image -- and 243.4 is exactly where the
 * map sends a vehicle's forward axis, world +Y, which projects to
 * (-64, +32) pixels. So the two cancel and no offset is left over.
 */
/**
 * Where the camera sits.
 *
 * The tilt and the turn together have to put a vehicle facing heading
 * zero where the map draws that direction: a vehicle's front is world
 * +Y, which projects to (-64, +32) pixels, 243.4 degrees clockwise from
 * the top of the image.
 *
 * Two yaws satisfy that, one viewing the vehicle's front and one its
 * back, and the arithmetic cannot tell them apart -- both put the long
 * axis on the same screen line. Checked in a browser against the same
 * taxi seen in the game: 225 shows the front.
 *
 * The heading itself is turned in the scene rather than on the finished
 * image, because the map's ground plane is squashed and a flat rotation
 * turns on a circle instead of that ellipse.
 */
export const YAW_DEGREES = 225

function isometricCamera(body: Group, margin = 1): OrthographicCamera {
  const box = new Box3().setFromObject(body)
  const size = box.getSize(new Vector3())

  // Aimed at the middle of the footprint but at the height the wheels
  // rest on, not the middle of the body.
  //
  // The models are not built around their own origin -- the taxi's
  // vertical centre sits 9 units above it -- so aiming at the box
  // centre lifted every vehicle in its frame and left the wheel arches
  // clear of the road they stand on.
  const centre = new Vector3(
    (box.min.x + box.max.x) / 2,
    box.min.y,
    (box.min.z + box.max.z) / 2,
  )

  const ground = Math.hypot(size.x, size.z)
  const raised = size.y * Math.cos(degrees(ELEVATION_DEGREES)) * HEIGHT_CORRECTION

  // No margin by default: the map's marker sizes the picture by the
  // vehicle's real length, so padding here would draw every vehicle
  // short. The catalogue preview asks for room, to fit its road.
  const half = (Math.max(ground, raised) / 2) * margin

  const camera = new OrthographicCamera(-half, half, half, -half, 0.1, ground * 6 + size.y * 4)
  const distance = ground * 3 + size.y * 2
  const elevation = degrees(ELEVATION_DEGREES)
  const yaw = degrees(YAW_DEGREES)

  camera.position.set(
    centre.x + distance * Math.cos(elevation) * Math.sin(yaw),
    centre.y + distance * Math.sin(elevation),
    centre.z + distance * Math.cos(elevation) * Math.cos(yaw),
  )
  camera.lookAt(centre)

  return camera
}

/**
 * The node the body's geometry actually hangs on.
 *
 * FBXLoader wraps the mesh in a node carrying the file's axis
 * conversion, so anything placed in the body's own coordinates has to
 * go on that node rather than on the group above it.
 */
function bodyFrame(body: Group): Object3D {
  let frame: Object3D = body

  body.traverse((node) => {
    if (node instanceof Mesh && node.parent !== null) {
      frame = node.parent
    }
  })

  return frame
}

/**
 * How much wider the preview is framed when it carries a road, so the
 * verge and bushes have somewhere to be.
 */
const SCENERY_MARGIN = 1.55

/** The game's own tiles and props, extracted from its texture packs. */
const FLOOR_STREET = 'floor_street.png'
const FLOOR_GRASS = 'floor_grass.png'
const SCENERY_BUSH = 'scenery_bush.png'

/**
 * Ground the vehicle stands on: a road with a grass verge and bushes.
 *
 * The tiles are the game's own, and each pictures one world square seen
 * from this very angle -- the map's 2:1 projection is sin(30 degrees),
 * which is the elevation the camera already uses. So a tile goes on the
 * ground plane as a plain square and comes back out as the diamond it
 * was drawn as, with no distortion and no rotation.
 *
 * That is why the vehicle stands *on* the road rather than in front of
 * it: the tiles are in the world, at the height the wheels rest on, not
 * pinned to the camera.
 *
 * The bushes stay camera-facing, because a bush sprite is drawn facing
 * the viewer in the game too.
 */
function sceneryUnder(
  body: Object3D,
  street: Texture | null,
  grass: Texture | null,
  bush: Texture | null,
  camera: OrthographicCamera,
): Object3D[] {
  const bounds = new Box3().setFromObject(body)
  const size = bounds.getSize(new Vector3())

  // One tile is one world square, and a vehicle is a few squares long.
  const tile = Math.max(size.x, size.z) / 2.2
  const centre = new Vector3(
    (bounds.min.x + bounds.max.x) / 2,
    bounds.min.y,
    (bounds.min.z + bounds.max.z) / 2,
  )

  const pieces: Object3D[] = []

  /**
   * One ground tile, at world square (column, row) from the centre.
   *
   * The tile pictures a diamond with transparent corners, but a plane's
   * texture is mapped onto its square -- so the picture is 45 degrees out
   * of step with the projection, which is what left a chequerboard of
   * holes. Turning the plane by 45 degrees about the vertical and growing
   * it by root two puts the diamond's points on the square's edges, where
   * they meet the neighbours'.
   */
  const ground = (texture: Texture | null, column: number, row: number, drop: number) => {
    if (texture === null) {
      return
    }

    const plane = new Mesh(
      new PlaneGeometry(tile * Math.SQRT2, tile * Math.SQRT2),
      new MeshBasicMaterial({
        map: texture,
        transparent: true,
        alphaTest: 0.05,
        depthWrite: false,
      }),
    )

    plane.rotation.x = -Math.PI / 2
    plane.rotation.z = Math.PI / 4
    plane.position.set(
      centre.x + column * tile,
      // Stacked by a hair, so the road covers the verge it overlaps
      // rather than flickering against it.
      centre.y - drop * tile,
      centre.z + row * tile,
    )

    pieces.push(plane)
  }

  // Grass over the whole field, road on the squares under and around the
  // vehicle. A road running along one world axis would cut diagonally
  // across the picture, because a vehicle is drawn turned within its
  // square -- so the tarmac is a patch, and the grass is its verge.
  for (let column = -3; column <= 3; column += 1) {
    for (let row = -3; row <= 3; row += 1) {
      ground(grass, column, row, 0.004)
    }
  }

  for (let column = -2; column <= 2; column += 1) {
    for (let row = -2; row <= 2; row += 1) {
      if (Math.abs(column) + Math.abs(row) <= 3) {
        ground(street, column, row, 0.002)
      }
    }
  }

  /** A camera-facing sprite standing on the ground at a world position. */
  const standing = (
    texture: Texture | null,
    column: number,
    row: number,
    height: number,
  ) => {
    if (texture === null) {
      return
    }

    const plane = new Mesh(
      new PlaneGeometry(tile * height, tile * height),
      new MeshBasicMaterial({ map: texture, transparent: true, alphaTest: 0.5 }),
    )

    plane.quaternion.copy(camera.quaternion)
    plane.position.set(
      centre.x + column * tile,
      centre.y + (tile * height) / 2.4,
      centre.z + row * tile,
    )

    pieces.push(plane)
  }

  // Fixed positions rather than random ones, so a vehicle's preview does
  // not change between renders. The camera sits at negative x and z, so
  // the far verge is the positive corner and no bush stands in front.
  standing(bush, 2.6, 1.4, 1.1)
  standing(bush, 1.5, 2.6, 0.85)
  standing(bush, 2.9, 2.7, 0.65)

  return pieces
}

function degrees(value: number): number {
  return (value * Math.PI) / 180
}

/**
 * The paint, as the game stores it.
 *
 * Hue, saturation and value are floats from 0 to 1, and the game halves
 * the saturation before rendering. A vehicle with no mask is not
 * painted at all.
 */
function tintOf(vehicle: MapVehicle, artwork: VehicleArtwork): Color | null {
  const { hue, saturation, value } = vehicle

  if (
    artwork.mask === null ||
    typeof hue !== 'number' ||
    typeof saturation !== 'number' ||
    typeof value !== 'number'
  ) {
    return null
  }

  return new Color().setHSL(hue, saturation * 0.5, Math.max(0.15, value * 0.6))
}

/**
 * Type, paint and heading decide the picture; position does not.
 *
 * The heading is part of it because the turn happens in the scene --
 * see render() -- so each direction is its own image. Bucketed by
 * HEADING_STEP_DEGREES, which caps a type at 24 renders however many
 * vehicles of it the world holds.
 */
export function cacheKey(
  vehicle: MapVehicle,
  artwork: VehicleArtwork,
  options: DrawOptions = {},
): string {
  const paint = [vehicle.hue, vehicle.saturation, vehicle.value]
    .map((part) => (typeof part === 'number' ? part.toFixed(2) : '-'))
    .join(',')

  // The texture belongs in the key: three race cars share one model and
  // differ only in their shell, so keying on the model alone served the
  // first one's render for all three.
  return [
    artwork.model,
    artwork.texture ?? '-',
    vehicle.skin ?? 0,
    paint,
    bucketedHeading(vehicle),
    options.ground === true ? 'ground' : '-',
  ].join('|')
}

/** The heading rounded to the step the cache is keyed by. */
export function bucketedHeading(vehicle: MapVehicle): number {
  const heading = vehicle.heading ?? 0

  return (
    ((Math.round(heading / HEADING_STEP_DEGREES) * HEADING_STEP_DEGREES) % 360 + 360) % 360
  )
}

function disposeGroup(group: Group): void {
  group.traverse((node) => {
    if (!(node instanceof Mesh)) {
      return
    }

    node.geometry.dispose()

    for (const material of [node.material].flat()) {
      ;(material as Material).dispose()
    }
  })
}
