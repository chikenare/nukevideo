<?php

return [
    'codecs' => [
        // ========== VIDEO CODECS ==========
        [
            'codec' => 'libx264',
            'type' => 'video',
            'label' => 'H.264 (AVC)',
            'description' => 'High compatibility. Works everywhere.',

            'protocols' => ['hls', 'dash'],
        ],
        [
            'codec' => 'libx265',
            'type' => 'video',
            'label' => 'H.265 (HEVC)',
            'description' => 'Better compression than H.264. Good for 4K.',

            'protocols' => ['hls', 'dash'],
        ],
        [
            'codec' => 'libsvtav1',
            'type' => 'video',
            'label' => 'AV1 (SVT-AV1)',
            'description' => 'Best compression, royalty-free. Slow encoding.',

            'protocols' => ['hls', 'dash'],
        ],

        // ========== GPU VIDEO CODECS (NVIDIA NVENC) ==========
        [
            'codec' => 'h264_nvenc',
            'type' => 'video',
            'label' => 'H.264 (NVENC)',
            'description' => 'GPU-accelerated H.264. Fast encoding with NVIDIA hardware.',

            'protocols' => ['hls', 'dash'],
            'requires_gpu' => true,
        ],
        [
            'codec' => 'hevc_nvenc',
            'type' => 'video',
            'label' => 'H.265 (NVENC)',
            'description' => 'GPU-accelerated HEVC. Fast encoding, good for 4K.',

            'protocols' => ['hls', 'dash'],
            'requires_gpu' => true,
        ],
        [
            'codec' => 'av1_nvenc',
            'type' => 'video',
            'label' => 'AV1 (NVENC)',
            'description' => 'GPU-accelerated AV1. Requires RTX 40 series or newer.',

            'protocols' => ['hls', 'dash'],
            'requires_gpu' => true,
        ],

        // ========== AUDIO CODECS ==========
        [
            'codec' => 'aac',
            'type' => 'audio',
            'label' => 'AAC',
            'description' => 'Standard audio codec with good compatibility.',

            'available_for' => ['libx264', 'libx265', 'libsvtav1', 'h264_nvenc', 'hevc_nvenc', 'av1_nvenc'],
        ],
        [
            'codec' => 'libfdk_aac',
            'type' => 'audio',
            'label' => 'FDK-AAC',
            'description' => 'The best AAC encoder available.',

            'available_for' => ['libx264', 'libx265', 'libsvtav1', 'h264_nvenc', 'hevc_nvenc', 'av1_nvenc'],
        ],
        [
            'codec' => 'libopus',
            'type' => 'audio',
            'label' => 'Opus',
            'description' => 'Best open-source audio codec. Low latency, high quality.',

            'available_for' => ['libsvtav1', 'av1_nvenc'],
        ],
    ],

    'parameters' => [
        // ==========================================
        // VIDEO PARAMETERS
        // ==========================================

        'width' => [
            'type' => 'video',
            'input_type' => 'integer',
            'label' => 'Width',
            'min' => 128,
            'max' => 3840,
            'rules' => ['required', 'integer', 'min:128', 'max:3840'],
            'template' => null,
            'available_for' => ['libx264', 'libx265', 'libsvtav1', 'h264_nvenc', 'hevc_nvenc', 'av1_nvenc'],
        ],

        'height' => [
            'type' => 'video',
            'input_type' => 'integer',
            'label' => 'Height',
            'min' => 128,
            'max' => 2160,
            'rules' => ['required', 'integer', 'min:128', 'max:2160'],
            'template' => null,
            'available_for' => ['libx264', 'libx265', 'libsvtav1', 'h264_nvenc', 'hevc_nvenc', 'av1_nvenc'],
        ],

        // --- H.264 specific ---
        'crf' => [
            'type' => 'video',
            'input_type' => 'integer',
            'label' => 'CRF',
            'min' => 0,
            'max' => 51,
            'help' => 'Lower is better quality. 23 is the default for H.264.',
            'rules' => ['integer', 'min:0', 'max:51'],
            'template' => '-crf %s',
            'available_for' => ['libx264', 'libx265'],
        ],
        'preset' => [
            'type' => 'video',
            'input_type' => 'select',
            'label' => 'Preset',
            'options' => ['ultrafast', 'superfast', 'veryfast', 'faster', 'fast', 'medium', 'slow', 'slower', 'veryslow'],
            'rules' => ['in:ultrafast,superfast,veryfast,faster,fast,medium,slow,slower,veryslow'],
            'template' => '-preset %s',
            'available_for' => ['libx264', 'libx265'],
        ],
        'video_profile' => [
            'type' => 'video',
            'input_type' => 'select',
            'label' => 'Profile',
            'options' => ['baseline', 'main', 'high'],
            'rules' => ['in:baseline,main,high'],
            'template' => '-profile:v %s',
            'available_for' => ['libx264'],
        ],
        'tune' => [
            'type' => 'video',
            'input_type' => 'select',
            'label' => 'Tune',
            'options' => ['film', 'animation', 'grain', 'stillimage', 'fastdecode', 'zerolatency'],
            'help' => 'Adjust the encoder according to the type of content.',
            'rules' => ['in:film,animation,grain,stillimage,fastdecode,zerolatency'],
            'template' => '-tune %s',
            'available_for' => ['libx264'],
        ],
        'level' => [
            'type' => 'video',
            'input_type' => 'select',
            'label' => 'Level',
            'options' => ['3.0', '3.1', '4.0', '4.1', '4.2', '5.1', '5.2'],
            'help' => 'Defines technical restrictions. 4.1 is the standard for Blu-ray/Web.',
            'rules' => ['in:3.0,3.1,4.0,4.1,4.2,5.1,5.2'],
            'template' => '-level %s',
            'available_for' => ['libx264'],
        ],
        'maxrate' => [
            'type' => 'video',
            'input_type' => 'text',
            'label' => 'Maxrate',
            'placeholder' => 'e.g. 5000k or 5M',
            'help' => 'Useful for streaming (VBV). Prevents data spikes that cause buffering.',
            'rules' => ['regex:/^\d+[kKmM]?$/'],
            'template' => '-maxrate %s',
            'available_for' => ['libx264', 'libx265', 'libsvtav1', 'h264_nvenc', 'hevc_nvenc', 'av1_nvenc'],
        ],
        'bufsize' => [
            'type' => 'video',
            'input_type' => 'text',
            'label' => 'Bufsize',
            'placeholder' => 'e.g. 10000k or 10M',
            'help' => 'Used together with Maxrate to control the bitrate.',
            'rules' => ['regex:/^\d+[kKmM]?$/'],
            'template' => '-bufsize %s',
            'available_for' => ['libx264', 'libx265', 'libsvtav1', 'h264_nvenc', 'hevc_nvenc', 'av1_nvenc'],
        ],
        'pixel_format' => [
            'type' => 'video',
            'input_type' => 'select',
            'label' => 'Pixel format',
            'options' => ['yuv420p', 'yuv420p10le', 'yuv422p', 'yuv444p'],
            'help' => 'yuv420p is compatible with all browsers and devices. 10le for HDR/10-bit.',
            'rules' => ['in:yuv420p,yuv420p10le,yuv422p,yuv444p'],
            'template' => '-pix_fmt %s',
            'available_for' => ['libx264', 'libx265', 'libsvtav1', 'h264_nvenc', 'hevc_nvenc', 'av1_nvenc'],
        ],
        'faststart' => [
            'type' => 'video',
            'input_type' => 'boolean',
            'label' => 'Faststart',
            'help' => 'Moves metadata to the beginning so the video starts playing sooner.',
            'rules' => ['boolean'],
            'template' => '-movflags +faststart',
            'available_for' => ['libx264', 'libx265', 'libsvtav1', 'h264_nvenc', 'hevc_nvenc', 'av1_nvenc'],
        ],
        'constant_bitrate' => [
            'type' => 'video',
            'input_type' => 'text',
            'label' => 'Target Bitrate',
            'placeholder' => 'e.g. 2500k or 2.5M',
            'help' => 'Target bitrate. Use 0 for CRF-only mode (VP9/VP8).',
            'rules' => ['regex:/^\d+[kKmM]?$/'],
            'template' => '-b:v %s',
            'available_for' => ['libx264', 'libx265', 'libsvtav1', 'h264_nvenc', 'hevc_nvenc', 'av1_nvenc'],
        ],
        'gop_size' => [
            'type' => 'video',
            'input_type' => 'integer',
            'label' => 'GOP Size (Keyframes)',
            'help' => 'Distance between keyframes. For streaming use 2x framerate (e.g. 60 for 30fps).',
            'rules' => ['nullable', 'integer', 'min:1'],
            'template' => '-g %s',
            'available_for' => ['libx264', 'libx265', 'libsvtav1', 'h264_nvenc', 'hevc_nvenc', 'av1_nvenc'],
        ],

        // --- H.265 specific ---
        'x265_profile' => [
            'type' => 'video',
            'input_type' => 'select',
            'label' => 'HEVC Profile',
            'options' => ['main', 'main10', 'main444-8', 'main444-10'],
            'rules' => ['in:main,main10,main444-8,main444-10'],
            'template' => '-profile:v %s',
            'available_for' => ['libx265'],
        ],
        'x265_tune' => [
            'type' => 'video',
            'input_type' => 'select',
            'label' => 'HEVC Tune',
            'options' => ['grain', 'animation', 'fastdecode', 'zerolatency'],
            'rules' => ['in:grain,animation,fastdecode,zerolatency'],
            'template' => '-tune %s',
            'available_for' => ['libx265'],
        ],
        'hevc_tag' => [
            'type' => 'video',
            'input_type' => 'boolean',
            'label' => 'Apple HVC1 Tag',
            'help' => 'Required for Apple device compatibility with HEVC.',
            'rules' => ['boolean'],
            'template' => '-tag:v hvc1',
            'available_for' => ['libx265'],
        ],

        // --- AV1 (SVT-AV1) specific ---
        'svtav1_crf' => [
            'type' => 'video',
            'input_type' => 'integer',
            'label' => 'CRF',
            'min' => 0,
            'max' => 63,
            'help' => 'Lower is better quality. 30 is a good default for AV1.',
            'rules' => ['integer', 'min:0', 'max:63'],
            'template' => '-crf %s',
            'available_for' => ['libsvtav1'],
        ],
        'svtav1_preset' => [
            'type' => 'video',
            'input_type' => 'integer',
            'label' => 'Preset',
            'min' => 0,
            'max' => 13,
            'help' => '0 = slowest/best quality, 13 = fastest. 6-8 is a good balance.',
            'rules' => ['integer', 'min:0', 'max:13'],
            'template' => '-preset %s',
            'available_for' => ['libsvtav1'],
        ],

        // --- NVENC (GPU) specific ---
        'nvenc_preset' => [
            'type' => 'video',
            'input_type' => 'select',
            'label' => 'NVENC Preset',
            'options' => ['p1', 'p2', 'p3', 'p4', 'p5', 'p6', 'p7'],
            'help' => 'p1 = fastest, p7 = best quality. p4 is a good default.',
            'rules' => ['in:p1,p2,p3,p4,p5,p6,p7'],
            'template' => '-preset %s',
            'available_for' => ['h264_nvenc', 'hevc_nvenc', 'av1_nvenc'],
        ],
        'nvenc_tune' => [
            'type' => 'video',
            'input_type' => 'select',
            'label' => 'NVENC Tune',
            'options' => ['hq', 'll', 'ull', 'lossless'],
            'help' => 'hq = high quality, ll = low latency, ull = ultra low latency.',
            'rules' => ['in:hq,ll,ull,lossless'],
            'template' => '-tune %s',
            'available_for' => ['h264_nvenc', 'hevc_nvenc'],
        ],
        'nvenc_rc' => [
            'type' => 'video',
            'input_type' => 'select',
            'label' => 'Rate Control',
            'options' => ['constqp', 'vbr', 'cbr'],
            'help' => 'constqp = constant quality, vbr = variable bitrate, cbr = constant bitrate.',
            'rules' => ['in:constqp,vbr,cbr'],
            'template' => '-rc %s',
            'available_for' => ['h264_nvenc', 'hevc_nvenc', 'av1_nvenc'],
        ],
        'nvenc_cq' => [
            'type' => 'video',
            'input_type' => 'integer',
            'label' => 'Quality Level (CQ)',
            'min' => 0,
            'max' => 51,
            'help' => 'Target quality when using VBR. Lower is better. 19-28 is typical.',
            'rules' => ['integer', 'min:0', 'max:51'],
            'template' => '-cq %s',
            'available_for' => ['h264_nvenc', 'hevc_nvenc', 'av1_nvenc'],
        ],
        'nvenc_h264_profile' => [
            'type' => 'video',
            'input_type' => 'select',
            'label' => 'H.264 Profile',
            'options' => ['baseline', 'main', 'high', 'high444p'],
            'rules' => ['in:baseline,main,high,high444p'],
            'template' => '-profile:v %s',
            'available_for' => ['h264_nvenc'],
        ],
        'nvenc_hevc_profile' => [
            'type' => 'video',
            'input_type' => 'select',
            'label' => 'HEVC Profile',
            'options' => ['main', 'main10', 'rext'],
            'rules' => ['in:main,main10,rext'],
            'template' => '-profile:v %s',
            'available_for' => ['hevc_nvenc'],
        ],
        'nvenc_spatial_aq' => [
            'type' => 'video',
            'input_type' => 'boolean',
            'label' => 'Spatial AQ',
            'help' => 'Adaptive quantization that allocates more bits to complex areas.',
            'rules' => ['boolean'],
            'template' => '-spatial-aq 1',
            'available_for' => ['h264_nvenc', 'hevc_nvenc', 'av1_nvenc'],
        ],
        'nvenc_temporal_aq' => [
            'type' => 'video',
            'input_type' => 'boolean',
            'label' => 'Temporal AQ',
            'help' => 'Allocates more bits to frames with high motion complexity.',
            'rules' => ['boolean'],
            'template' => '-temporal-aq 1',
            'available_for' => ['h264_nvenc', 'hevc_nvenc'],
        ],
        'nvenc_b_frames' => [
            'type' => 'video',
            'input_type' => 'integer',
            'label' => 'B-Frames',
            'min' => 0,
            'max' => 4,
            'help' => 'Number of B-frames. 0 disables. 2-3 is typical for quality.',
            'rules' => ['integer', 'min:0', 'max:4'],
            'template' => '-bf %s',
            'available_for' => ['h264_nvenc', 'hevc_nvenc'],
        ],
        'nvenc_gpu' => [
            'type' => 'video',
            'input_type' => 'integer',
            'label' => 'GPU Device',
            'min' => 0,
            'max' => 7,
            'help' => 'GPU device index. 0 is the first GPU. Only needed for multi-GPU systems.',
            'rules' => ['integer', 'min:0', 'max:7'],
            'template' => '-gpu %s',
            'available_for' => ['h264_nvenc', 'hevc_nvenc', 'av1_nvenc'],
        ],

        // ==========================================
        // AUDIO PARAMETERS
        // ==========================================

        'audio_bitrate' => [
            'type' => 'audio',
            'input_type' => 'select',
            'label' => 'Audio Bitrate',
            'options' => ['64k', '96k', '128k', '160k', '192k', '256k', '320k', '448k', '640k'],
            'rules' => ['in:64k,96k,128k,160k,192k,256k,320k,448k,640k'],
            'template' => '-b:a %s',
            'available_for' => ['aac', 'libfdk_aac', 'libopus'],
        ],
        'sample_rate' => [
            'type' => 'audio',
            'input_type' => 'select',
            'label' => 'Sample Rate',
            'options' => ['22050', '32000', '44100', '48000'],
            'help' => '44100Hz is the CD standard; 48000Hz is used in professional video.',
            'rules' => ['in:22050,32000,44100,48000'],
            'template' => '-ar %s',
            'available_for' => ['aac', 'libfdk_aac', 'libopus'],
        ],
        'channels' => [
            'type' => 'audio',
            'input_type' => 'select',
            'label' => 'Channels',
            'options' => ['1', '2', '6'],
            'help' => '1 = mono, 2 = stereo, 6 = 5.1 surround.',
            'rules' => ['in:1,2,6'],
            'template' => '-ac %s',
            'available_for' => ['aac', 'libfdk_aac', 'libopus'],
        ],

        // --- AAC specific ---
        'audio_vbr' => [
            'type' => 'audio',
            'input_type' => 'select',
            'label' => 'VBR (AAC native)',
            'options' => ['0', '1', '2', '3', '4', '5'],
            'help' => 'Variable bitrate. Lets the bitrate fluctuate based on audio complexity.',
            'rules' => ['in:0,1,2,3,4,5'],
            'template' => '-q:a %s',
            'available_for' => ['aac'],
        ],
        'audio_profile' => [
            'type' => 'audio',
            'input_type' => 'select',
            'label' => 'AAC Profile',
            'options' => ['aac_low', 'aac_he', 'aac_he_v2', 'aac_ld', 'aac_eld'],
            'help' => 'AAC-LC is the most compatible with all players.',
            'rules' => ['in:aac_low,aac_he,aac_he_v2,aac_ld,aac_eld'],
            'template' => '-profile:a %s',
            'available_for' => ['aac', 'libfdk_aac'],
        ],

        // --- FDK-AAC specific ---
        'audio_vbr_fdk' => [
            'type' => 'audio',
            'input_type' => 'select',
            'label' => 'VBR (FDK)',
            'options' => ['1', '2', '3', '4', '5'],
            'help' => 'VBR usually provides better quality than CBR at the same file size.',
            'rules' => ['in:1,2,3,4,5'],
            'template' => '-vbr %s',
            'available_for' => ['libfdk_aac'],
        ],
        'cutoff' => [
            'type' => 'audio',
            'input_type' => 'integer',
            'label' => 'Cutoff',
            'min' => 0,
            'max' => 20000,
            'help' => '0 lets the encoder decide. Limits high frequencies.',
            'rules' => ['integer', 'min:0', 'max:20000'],
            'template' => '-cutoff %s',
            'available_for' => ['libfdk_aac'],
        ],
        'afterburner' => [
            'type' => 'audio',
            'input_type' => 'boolean',
            'label' => 'Afterburner',
            'help' => 'Slightly improves quality at the cost of more CPU.',
            'rules' => ['boolean'],
            'template' => '-afterburner %s',
            'available_for' => ['libfdk_aac'],
        ],

        // --- Opus specific ---
        'opus_vbr' => [
            'type' => 'audio',
            'input_type' => 'select',
            'label' => 'VBR Mode',
            'options' => ['on', 'off', 'constrained'],
            'help' => '"on" is recommended for best quality. "constrained" limits peaks.',
            'rules' => ['in:on,off,constrained'],
            'template' => '-vbr %s',
            'available_for' => ['libopus'],
        ],
        'opus_application' => [
            'type' => 'audio',
            'input_type' => 'select',
            'label' => 'Application',
            'options' => ['audio', 'voip', 'lowdelay'],
            'help' => '"audio" for music/general, "voip" for speech, "lowdelay" for real-time.',
            'rules' => ['in:audio,voip,lowdelay'],
            'template' => '-application %s',
            'available_for' => ['libopus'],
        ],

    ],

    'formats' => [
        'hls' => [
            'label' => 'HLS',
            'description' => 'HTTP Live Streaming. Best for Apple devices and broad compatibility.',
            'protocols' => ['hls'],

        ],
        'dash' => [
            'label' => 'DASH',
            'description' => 'Dynamic Adaptive Streaming over HTTP. Supports all codecs.',
            'protocols' => ['dash'],

        ],
        'mp4' => [
            'label' => 'MP4',
            'description' => 'Progressive download. Single file output.',
            'protocols' => [],

        ],
    ],
];
