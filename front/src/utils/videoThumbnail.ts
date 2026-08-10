/** Give up rather than hold the caller: a poster frame is a nicety, not a precondition. */
const THUMBNAIL_TIMEOUT_MS = 5000

/**
 * Generates a thumbnail from a video file.
 *
 * Always settles, and resolves to an empty string when the browser cannot decode the file. No
 * browser demuxes Matroska, and `.mkv` is an accepted upload format — a promise that only ever
 * resolved from `onseeked` simply never settled for those, which silently froze the whole
 * enqueueing loop and left the upload dialog dead until a reload.
 *
 * @param file - The video file to generate a thumbnail from
 * @returns A promise that resolves to a base64 data URL, or '' if none could be produced
 */
export const generateThumbnail = (file: File): Promise<string> => {
    return new Promise((resolve) => {
        const video = document.createElement('video')
        const canvas = document.createElement('canvas')
        const context = canvas.getContext('2d')
        const src = URL.createObjectURL(file)

        let settled = false
        let timer: ReturnType<typeof setTimeout> | null = null

        const finish = (dataUrl: string) => {
            if (settled) return
            settled = true
            if (timer) clearTimeout(timer)
            URL.revokeObjectURL(src)
            video.removeAttribute('src')
            resolve(dataUrl)
        }

        video.preload = 'metadata'
        video.muted = true
        video.playsInline = true

        video.onloadedmetadata = () => {
            video.currentTime = Math.min(1, video.duration / 2)
        }

        video.onseeked = () => {
            canvas.width = video.videoWidth
            canvas.height = video.videoHeight
            context?.drawImage(video, 0, 0, canvas.width, canvas.height)
            finish(canvas.toDataURL('image/jpeg', 0.7))
        }

        // A codec the browser lacks fires `error`; a container it cannot even parse may fire
        // nothing at all, which is what the timeout is for.
        video.onerror = () => finish('')
        timer = setTimeout(() => finish(''), THUMBNAIL_TIMEOUT_MS)

        video.src = src
    })
}
