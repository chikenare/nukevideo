import prettyBytes from 'pretty-bytes'

/** Every size in the panel reads in decimal units (MB/GB), the way storage and bandwidth are billed. */
export const formatBytes = (bytes?: number | null): string => prettyBytes(bytes ?? 0)
