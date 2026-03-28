/**
 * Generates a thumbnail from a video file
 * @param file - The video file to generate a thumbnail from
 * @returns A promise that resolves to a base64 data URL of the thumbnail
 */
export const generateThumbnail = (file: File): Promise<string> => {
    return new Promise((resolve) => {
        const video = document.createElement('video')
        const canvas = document.createElement('canvas')
        const context = canvas.getContext('2d')

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
            resolve(canvas.toDataURL('image/jpeg', 0.7))
            URL.revokeObjectURL(video.src)
        }

        video.src = URL.createObjectURL(file)
    })
}
