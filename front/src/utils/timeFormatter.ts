/**
 * Formats seconds into a human-readable time string
 * @param seconds - The total number of seconds to format
 * @param options - Optional formatting options
 * @returns Formatted time string (e.g., "1:23:45", "5:30", "0:05")
 */
export function formatSecondsToTime(
  seconds: number,
  options: {
    alwaysShowHours?: boolean
    padMinutes?: boolean
  } = {}
): string {
  const { alwaysShowHours = false, padMinutes = false } = options

  // Handle negative values
  const isNegative = seconds < 0
  const absSeconds = Math.abs(seconds)

  // Calculate hours, minutes, and seconds
  const hours = Math.floor(absSeconds / 3600)
  const minutes = Math.floor((absSeconds % 3600) / 60)
  const secs = Math.floor(absSeconds % 60)

  // Format the parts
  const parts: string[] = []

  if (hours > 0 || alwaysShowHours) {
    parts.push(hours.toString())
    // When hours are shown, always pad minutes
    parts.push(minutes.toString().padStart(2, '0'))
  } else {
    // No hours - either pad minutes or not based on option
    parts.push(padMinutes ? minutes.toString().padStart(2, '0') : minutes.toString())
  }

  // Always pad seconds
  parts.push(secs.toString().padStart(2, '0'))

  const formatted = parts.join(':')
  return isNegative ? `-${formatted}` : formatted
}

/**
 * Formats seconds into a detailed time string with labels
 * @param seconds - The total number of seconds to format
 * @returns Formatted time string with labels (e.g., "1h 23m 45s", "5m 30s")
 */
export function formatSecondsToDetailedTime(seconds: number): string {
  const absSeconds = Math.abs(seconds)
  const isNegative = seconds < 0

  const hours = Math.floor(absSeconds / 3600)
  const minutes = Math.floor((absSeconds % 3600) / 60)
  const secs = Math.floor(absSeconds % 60)

  const parts: string[] = []

  if (hours > 0) {
    parts.push(`${hours}h`)
  }
  if (minutes > 0) {
    parts.push(`${minutes}m`)
  }
  if (secs > 0 || parts.length === 0) {
    parts.push(`${secs}s`)
  }

  const formatted = parts.join(' ')
  return isNegative ? `-${formatted}` : formatted
}

/**
 * Parses a time string (HH:MM:SS or MM:SS) back to seconds
 * @param timeString - Time string to parse (e.g., "1:23:45", "5:30")
 * @returns Total number of seconds
 */
export function parseTimeToSeconds(timeString: string): number {
  const parts = timeString.split(':').map((part) => parseInt(part, 10))

  if (parts.length === 2) {
    // MM:SS format
    const [minutes, seconds] = parts
    return minutes! * 60 + seconds!
  } else if (parts.length === 3) {
    // HH:MM:SS format
    const [hours, minutes, seconds] = parts
    return hours! * 3600 + minutes! * 60 + seconds!
  }

  throw new Error('Invalid time format. Expected HH:MM:SS or MM:SS')
}
