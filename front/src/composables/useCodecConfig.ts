import { ref, computed } from 'vue'
import type { CodecConfig, Codec, Parameter } from '@/types/CodecConfig'
import TemplateService from '@/services/TemplateService'

export function useCodecConfig() {
    const config = ref<CodecConfig | null>(null)
    const loading = ref(false)

    const fetchConfig = async () => {
        loading.value = true
        try {
            config.value = await TemplateService.getCodecsConfig()
        } catch (error) {
            console.error('Error fetching codec config:', error)
        } finally {
            loading.value = false
        }
    }

    const getVideoCodecs = computed((): Codec[] => {
        return config.value?.codecs.filter(c => c.type === 'video') || []
    })

    const getAudioCodecs = (videoCodec?: string): Codec[] => {
        if (!config.value) return []

        return config.value.codecs.filter(c => {
            if (c.type !== 'audio') return false
            if (!videoCodec) return true
            if (!c.available_for || c.available_for.length === 0) return true
            return c.available_for.includes(videoCodec)
        })
    }

    const getParametersForCodec = (codecName: string, type: 'video' | 'audio'): Record<string, Parameter> => {
        if (!config.value) return {}

        const filtered: Record<string, Parameter> = {}

        Object.entries(config.value.parameters).forEach(([key, param]) => {
            if (param.type === type && param.available_for.includes(codecName)) {
                filtered[key] = param
            }
        })

        return filtered
    }

    return {
        config,
        loading,
        fetchConfig,
        getVideoCodecs,
        getAudioCodecs,
        getParametersForCodec,
    }
}
