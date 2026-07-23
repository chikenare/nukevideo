<?php

return [

    'codecs' => [
        // ========== VIDEO CODECS ==========
        [
            'codec' => 'libx264',
            'type' => 'video',
            'label' => 'H.264 (AVC)',

            // Output muxer (`-f`) used when encoding this codec; see EncodeCommandBuilder.
            'format' => 'mp4',
            'protocols' => ['hls', 'dash'],
        ],
        [
            'codec' => 'libx265',
            'type' => 'video',
            'label' => 'H.265 (HEVC)',

            'format' => 'mp4',
            'protocols' => ['hls', 'dash'],
        ],
        [
            'codec' => 'libsvtav1',
            'type' => 'video',
            'label' => 'AV1 (SVT-AV1)',

            'format' => 'mp4',
            'protocols' => ['hls', 'dash'],
        ],

        // ========== GPU VIDEO CODECS ==========
        // `accel` routes the rendition's chunk jobs to nodes with that hardware
        // (absent/null = CPU, any worker). See ChunkTranscodeService::accelForCodec().
        [
            'codec' => 'h264_qsv',
            'type' => 'video',
            'label' => 'H.264 (Intel QSV)',

            'format' => 'mp4',
            'protocols' => ['hls', 'dash'],
            'accel' => 'intel',
        ],
        [
            'codec' => 'hevc_qsv',
            'type' => 'video',
            'label' => 'H.265 (Intel QSV)',

            'format' => 'mp4',
            'protocols' => ['hls', 'dash'],
            'accel' => 'intel',
        ],
        [
            'codec' => 'av1_qsv',
            'type' => 'video',
            'label' => 'AV1 (Intel QSV)',

            'format' => 'mp4',
            'protocols' => ['hls', 'dash'],
            'accel' => 'intel',
        ],
        [
            'codec' => 'h264_nvenc',
            'type' => 'video',
            'label' => 'H.264 (NVIDIA NVENC)',

            'format' => 'mp4',
            'protocols' => ['hls', 'dash'],
            'accel' => 'nvidia',
        ],
        [
            'codec' => 'hevc_nvenc',
            'type' => 'video',
            'label' => 'H.265 (NVIDIA NVENC)',

            'format' => 'mp4',
            'protocols' => ['hls', 'dash'],
            'accel' => 'nvidia',
        ],
        [
            'codec' => 'av1_nvenc',
            'type' => 'video',
            'label' => 'AV1 (NVIDIA NVENC)',

            'format' => 'mp4',
            'protocols' => ['hls', 'dash'],
            'accel' => 'nvidia',
        ],

        // ========== AUDIO CODECS ==========
        [
            'codec' => 'aac',
            'type' => 'audio',
            'label' => 'AAC',

            'format' => 'mp4',
            // nginx-vod-module: AAC is packaged for both HLS and DASH.
            'protocols' => ['hls', 'dash'],
            'available_for' => ['libx264', 'libx265', 'libsvtav1', 'h264_qsv', 'hevc_qsv', 'av1_qsv', 'h264_nvenc', 'hevc_nvenc', 'av1_nvenc'],
        ],
        [
            'codec' => 'libfdk_aac',
            'type' => 'audio',
            'label' => 'FDK-AAC',

            'format' => 'mp4',
            // nginx-vod-module: AAC is packaged for both HLS and DASH.
            'protocols' => ['hls', 'dash'],
            'available_for' => ['libx264', 'libx265', 'libsvtav1', 'h264_qsv', 'hevc_qsv', 'av1_qsv', 'h264_nvenc', 'hevc_nvenc', 'av1_nvenc'],
        ],
        [
            'codec' => 'libopus',
            'type' => 'audio',
            'label' => 'Opus',

            // nginx-vod reads MP4 input only (it can't identify a WebM container), but it
            // does support the Opus *codec* for DASH — so mux Opus into MP4 (ISO-BMFF `dOps`),
            // not WebM. ffmpeg's mp4 muxer writes the Opus sample entry natively.
            'format' => 'mp4',
            // nginx-vod-module only supports Opus in DASH, not HLS.
            'protocols' => ['dash'],
            'available_for' => ['libx264', 'libx265', 'libsvtav1', 'h264_qsv', 'hevc_qsv', 'av1_qsv', 'h264_nvenc', 'hevc_nvenc', 'av1_nvenc'],
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
            'available_for' => ['libx264', 'libx265', 'libsvtav1', 'h264_qsv', 'hevc_qsv', 'av1_qsv', 'h264_nvenc', 'hevc_nvenc', 'av1_nvenc'],
        ],

        'height' => [
            'type' => 'video',
            'input_type' => 'integer',
            'label' => 'Height',
            'min' => 128,
            'max' => 2160,
            'rules' => ['required', 'integer', 'min:128', 'max:2160'],
            'template' => null,
            'available_for' => ['libx264', 'libx265', 'libsvtav1', 'h264_qsv', 'hevc_qsv', 'av1_qsv', 'h264_nvenc', 'hevc_nvenc', 'av1_nvenc'],
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
            // No av1_qsv: the AV1 QSV runtime has no capped-quality mode (see qsv_global_quality).
            'available_for' => ['libx264', 'libx265', 'libsvtav1', 'h264_qsv', 'hevc_qsv', 'h264_nvenc', 'hevc_nvenc', 'av1_nvenc'],
        ],
        'bufsize' => [
            'type' => 'video',
            'input_type' => 'text',
            'label' => 'Bufsize',
            'placeholder' => 'e.g. 10000k or 10M',
            'help' => 'Used together with Maxrate to control the bitrate.',
            'rules' => ['regex:/^\d+[kKmM]?$/'],
            'template' => '-bufsize %s',
            // No av1_qsv: see maxrate.
            'available_for' => ['libx264', 'libx265', 'libsvtav1', 'h264_qsv', 'hevc_qsv', 'h264_nvenc', 'hevc_nvenc', 'av1_nvenc'],
        ],
        'pixel_format' => [
            'type' => 'video',
            'input_type' => 'select',
            'label' => 'Pixel format',
            'options' => ['yuv420p', 'yuv420p10le', 'yuv422p', 'yuv444p'],
            'help' => 'yuv420p is compatible with all browsers and devices. 10le for HDR/10-bit.',
            'rules' => ['in:yuv420p,yuv420p10le,yuv422p,yuv444p'],
            'template' => '-pix_fmt %s',
            // CPU only: GPU encoders take nv12/p010 and ffmpeg auto-inserts the conversion.
            'available_for' => ['libx264', 'libx265', 'libsvtav1'],
        ],
        'constant_bitrate' => [
            'type' => 'video',
            'input_type' => 'text',
            'label' => 'Target Bitrate',
            'placeholder' => 'e.g. 2500k or 2.5M',
            'help' => 'Target bitrate. Use 0 for CRF-only mode (VP9/VP8).',
            'rules' => ['regex:/^\d+[kKmM]?$/'],
            'template' => '-b:v %s',
            'available_for' => ['libx264', 'libx265', 'libsvtav1', 'h264_qsv', 'hevc_qsv', 'av1_qsv', 'h264_nvenc', 'hevc_nvenc', 'av1_nvenc'],
        ],
        'gop_size' => [
            'type' => 'video',
            'input_type' => 'integer',
            'label' => 'GOP Size (Keyframes)',
            'help' => 'Distance between keyframes. For streaming use 2x framerate (e.g. 60 for 30fps).',
            'rules' => ['nullable', 'integer', 'min:1'],
            'template' => '-g %s',
            'available_for' => ['libx264', 'libx265', 'libsvtav1', 'h264_qsv', 'hevc_qsv', 'av1_qsv', 'h264_nvenc', 'hevc_nvenc', 'av1_nvenc'],
        ],

        // `template` is null: consumed by PerTitleCrfService before fan-out, never rendered
        // into the ffmpeg command.
        'target_vmaf' => [
            'type' => 'video',
            'input_type' => 'integer',
            'label' => 'Target VMAF',
            'min' => 70,
            'max' => 99,
            'help' => 'Per-title mode: short samples of the source are test-encoded and the CRF is adjusted per video to hit this score. Empty = use the CRF as-is.',
            'rules' => ['nullable', 'integer', 'min:70', 'max:99'],
            'template' => null,
            // av1_qsv: ICQ is CRF-like, the same probe/interpolation applies (probe encodes need
            // the GPU, so prep must run on an accel node). Not h264/hevc_qsv (QVBR steering
            // interplay unverified) nor nvenc (no hardware to verify).
            'available_for' => ['libx264', 'libx265', 'libsvtav1', 'av1_qsv'],
        ],

        // --- ABR alignment (nginx-vod) ---
        // -keyint_min is only honoured by libx264; x265/av1 use their own *-params
        // families, so it's scoped to libx264 to avoid a silent no-op.
        // NOTE: scene-cut suppression and closed GOP are NOT template params — they're
        // mandatory for ABR, so ChunkTranscodeService forces them per codec (libx264
        // `-sc_threshold 0 -x264-params open-gop=0`, libx265 `-x265-params scenecut=0:open-gop=0`,
        // libsvtav1 `-svtav1-params scd=0`) so keyframes land on the `-g` grid and stay
        // aligned across renditions. See app/Services/ChunkTranscodeService.php.
        'min_gop_size' => [
            'type' => 'video',
            'input_type' => 'integer',
            'label' => 'Min GOP Size',
            'help' => 'Minimum distance between keyframes. Set equal to GOP Size to lock a strict, constant GOP.',
            'rules' => ['nullable', 'integer', 'min:1'],
            'template' => '-keyint_min %s',
            'available_for' => ['libx264'],
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
        // `template` is null on these: ffmpeg keeps only the last -svtav1-params flag, so
        // ChunkTranscodeService::svtAv1Params() joins each `svtav1_param` key with the forced
        // ABR pairs (scd/lp) into a single flag. Option sets follow the ffmpeg build pinned
        // in the Dockerfile (SVT-AV1 v4.x).
        'svtav1_tune' => [
            'type' => 'video',
            'input_type' => 'select',
            'label' => 'Tune',
            'options' => ['0', '1', '2'],
            'help' => '0 = VQ (subjective quality), 1 = PSNR (default), 2 = SSIM.',
            'rules' => ['in:0,1,2'],
            'template' => null,
            'svtav1_param' => 'tune',
            'available_for' => ['libsvtav1'],
        ],
        'svtav1_film_grain' => [
            'type' => 'video',
            'input_type' => 'integer',
            'label' => 'Film Grain Synthesis',
            'min' => 0,
            'max' => 50,
            'help' => 'Denoises the source and re-synthesizes grain at decode time. 8-15 for grainy film, 0 = off.',
            'rules' => ['nullable', 'integer', 'min:0', 'max:50'],
            'template' => null,
            'svtav1_param' => 'film-grain',
            'available_for' => ['libsvtav1'],
        ],
        'svtav1_variance_boost' => [
            'type' => 'video',
            'input_type' => 'boolean',
            'label' => 'Variance Boost',
            'help' => 'Boosts quality in low-contrast/dark regions. Useful for anime and dark scenes.',
            'rules' => ['boolean'],
            'template' => null,
            'svtav1_param' => 'enable-variance-boost',
            'available_for' => ['libsvtav1'],
        ],

        // --- Intel QSV specific ---
        // Quality knob. ChunkTranscodeService steers the BRC mode: quality-only → ICQ; with
        // Maxrate it injects a lower -b:v to reach QVBR (h264/hevc — gq+maxrate alone would
        // select CQP and silently drop the cap). The AV1 runtime has no QVBR, so maxrate is
        // not offered for av1_qsv and it always runs ICQ.
        'qsv_global_quality' => [
            'type' => 'video',
            'input_type' => 'integer',
            'label' => 'Quality (ICQ)',
            'min' => 1,
            'max' => 51,
            'help' => 'Lower is better quality. 20-24 is a good range. Leave Target Bitrate empty to use quality mode.',
            // A QSV variant with neither knob would fall into CQP at the encoder's default QP.
            'rules' => ['required_without:constant_bitrate', 'integer', 'min:1', 'max:51'],
            'template' => '-global_quality %s',
            'available_for' => ['h264_qsv', 'hevc_qsv', 'av1_qsv'],
        ],
        'qsv_preset' => [
            'type' => 'video',
            'input_type' => 'select',
            'label' => 'Preset',
            'options' => ['veryfast', 'faster', 'fast', 'medium', 'slow', 'slower', 'veryslow'],
            'rules' => ['in:veryfast,faster,fast,medium,slow,slower,veryslow'],
            'template' => '-preset %s',
            'available_for' => ['h264_qsv', 'hevc_qsv', 'av1_qsv'],
        ],

        // --- NVIDIA NVENC specific ---
        // Quality knob: constant-quality VBR. ChunkTranscodeService forces `-b:v 0` alongside it
        // (unless the template pins a Target Bitrate) so NVENC's default 2M bitrate never caps it.
        'nvenc_cq' => [
            'type' => 'video',
            'input_type' => 'integer',
            'label' => 'Constant Quality (CQ)',
            'min' => 1,
            'max' => 51,
            'help' => 'Lower is better quality. 19-28 is a good range. Leave Target Bitrate empty to use quality mode.',
            // Without either knob NVENC falls back to its default 2M bitrate target.
            'rules' => ['required_without:constant_bitrate', 'integer', 'min:1', 'max:51'],
            'template' => '-cq %s',
            'available_for' => ['h264_nvenc', 'hevc_nvenc', 'av1_nvenc'],
        ],
        'nvenc_preset' => [
            'type' => 'video',
            'input_type' => 'select',
            'label' => 'Preset',
            'options' => ['p1', 'p2', 'p3', 'p4', 'p5', 'p6', 'p7'],
            'help' => 'p1 = fastest, p7 = slowest/best quality.',
            'rules' => ['in:p1,p2,p3,p4,p5,p6,p7'],
            'template' => '-preset %s',
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
];
