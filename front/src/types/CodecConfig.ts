export type CodecType = 'video' | 'audio'

export type Codec = {
    codec: string
    type: CodecType
    label: string
    description: string
    container?: string
    protocols?: string[]
    availableFor?: string[]
}

export type InputType = 'select' | 'integer' | 'boolean' | 'ktext'

export type Parameter = {
    type: CodecType
    inputType: InputType
    label: string
    options?: string[]
    min?: number
    max?: number
    placeholder?: string
    help?: string
    rules?: string[]
    template: string
    availableFor: string[]
}

export type FormatConfig = {
    label: string
    description: string
    protocols: string[]
    containers: string[]
}

export type CodecConfig = {
    codecs: Codec[]
    parameters: Record<string, Parameter>
    formats: Record<string, FormatConfig>
}
