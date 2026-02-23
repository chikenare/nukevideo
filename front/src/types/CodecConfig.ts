export type CodecType = 'video' | 'audio'

export type Codec = {
    codec: string
    type: CodecType
    label: string
    description: string
    available_for?: string[]
}

export type InputType = 'select' | 'integer' | 'boolean' | 'ktext'

export type Parameter = {
    type: CodecType
    input_type: InputType
    label: string
    options?: string[]
    min?: number
    max?: number
    placeholder?: string
    help?: string
    rules?: string[]
    template: string
    available_for: string[]
}

export type CodecConfig = {
    codecs: Codec[]
    parameters: Record<string, Parameter>
}
