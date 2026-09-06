/** Formatting shared by the render window's parts. */

export function size(bytes: number): string {
  return bytes < 1073741824
    ? `${(bytes / 1048576).toFixed(0)} MB`
    : `${(bytes / 1073741824).toFixed(1)} GB`
}

export function duration(totalSeconds: number): string {
  const total = Math.max(0, Math.round(totalSeconds))
  const hours = Math.floor(total / 3600)
  const minutes = Math.floor((total % 3600) / 60)

  return hours > 0 ? `${hours} h ${minutes} min` : `${minutes} min`
}
