<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Template Presets
    |--------------------------------------------------------------------------
    |
    | Pre-built encoding templates that users can adopt into their account.
    | Each preset must pass the same validation as user-created templates.
    |
    */

    'hls-h264-multi' => [
        'name' => 'HLS Streaming (H.264)',
        'description' => 'Multi-bitrate HLS with H.264. Maximum device compatibility.',
        'category' => 'streaming',
        'query' => [
            'outputs' => [
                [
                    'format' => 'hls',
                    'variants' => [
                        [
                            'video_codec' => 'libx264',
                            'width' => 1920,
                            'height' => 1080,
                            'crf' => 23,
                            'preset' => 'medium',
                            'video_profile' => 'high',
                            'level' => '4.1',
                            'pixel_format' => 'yuv420p',
                            'maxrate' => '5000k',
                            'bufsize' => '10000k',
                            'gop_size' => 60,
                        ],
                        [
                            'video_codec' => 'libx264',
                            'width' => 1280,
                            'height' => 720,
                            'crf' => 23,
                            'preset' => 'medium',
                            'video_profile' => 'main',
                            'level' => '3.1',
                            'pixel_format' => 'yuv420p',
                            'maxrate' => '2500k',
                            'bufsize' => '5000k',
                            'gop_size' => 60,
                        ],
                        [
                            'video_codec' => 'libx264',
                            'width' => 854,
                            'height' => 480,
                            'crf' => 25,
                            'preset' => 'medium',
                            'video_profile' => 'main',
                            'level' => '3.0',
                            'pixel_format' => 'yuv420p',
                            'maxrate' => '1200k',
                            'bufsize' => '2400k',
                            'gop_size' => 60,
                        ],
                    ],
                    'audio' => [
                        'audio_codec' => 'aac',
                        'channels' => [
                            ['channels' => '2', 'audio_bitrate' => '128k'],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'hls-hevc-4k' => [
        'name' => 'HLS 4K Premium (H.265)',
        'description' => '4K HEVC streaming with multiple quality levels and 5.1 surround audio.',
        'category' => 'streaming',
        'query' => [
            'outputs' => [
                [
                    'format' => 'hls',
                    'variants' => [
                        [
                            'video_codec' => 'libx265',
                            'width' => 3840,
                            'height' => 2160,
                            'crf' => 22,
                            'preset' => 'slow',
                            'x265_profile' => 'main10',
                            'pixel_format' => 'yuv420p10le',
                            'maxrate' => '12000k',
                            'bufsize' => '24000k',
                            'hevc_tag' => true,
                            'gop_size' => 60,
                        ],
                        [
                            'video_codec' => 'libx265',
                            'width' => 1920,
                            'height' => 1080,
                            'crf' => 23,
                            'preset' => 'medium',
                            'x265_profile' => 'main',
                            'pixel_format' => 'yuv420p',
                            'maxrate' => '4500k',
                            'bufsize' => '9000k',
                            'hevc_tag' => true,
                            'gop_size' => 60,
                        ],
                        [
                            'video_codec' => 'libx265',
                            'width' => 1280,
                            'height' => 720,
                            'crf' => 25,
                            'preset' => 'medium',
                            'x265_profile' => 'main',
                            'pixel_format' => 'yuv420p',
                            'maxrate' => '2000k',
                            'bufsize' => '4000k',
                            'hevc_tag' => true,
                            'gop_size' => 60,
                        ],
                    ],
                    'audio' => [
                        'audio_codec' => 'aac',
                        'channels' => [
                            ['channels' => '6', 'audio_bitrate' => '256k'],
                            ['channels' => '2', 'audio_bitrate' => '128k'],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'mp4-h264-simple' => [
        'name' => 'MP4 Download (H.264)',
        'description' => 'Simple 1080p MP4 for progressive download or sharing.',
        'category' => 'download',
        'query' => [
            'outputs' => [
                [
                    'format' => 'mp4',
                    'variants' => [
                        [
                            'video_codec' => 'libx264',
                            'width' => 1920,
                            'height' => 1080,
                            'crf' => 20,
                            'preset' => 'slow',
                            'video_profile' => 'high',
                            'level' => '4.1',
                            'pixel_format' => 'yuv420p',
                            'tune' => 'film',
                            'faststart' => true,
                        ],
                    ],
                    'audio' => [
                        'audio_codec' => 'aac',
                        'channels' => [
                            ['channels' => '2', 'audio_bitrate' => '192k'],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'dash-av1-efficient' => [
        'name' => 'DASH AV1 (Best Compression)',
        'description' => 'AV1 encoding for maximum compression. Slower to encode but smallest files.',
        'category' => 'streaming',
        'query' => [
            'outputs' => [
                [
                    'format' => 'dash',
                    'variants' => [
                        [
                            'video_codec' => 'libsvtav1',
                            'width' => 1920,
                            'height' => 1080,
                            'svtav1_crf' => 30,
                            'svtav1_preset' => 6,
                            'pixel_format' => 'yuv420p',
                            'maxrate' => '3000k',
                            'bufsize' => '6000k',
                            'gop_size' => 60,
                        ],
                        [
                            'video_codec' => 'libsvtav1',
                            'width' => 1280,
                            'height' => 720,
                            'svtav1_crf' => 32,
                            'svtav1_preset' => 6,
                            'pixel_format' => 'yuv420p',
                            'maxrate' => '1500k',
                            'bufsize' => '3000k',
                            'gop_size' => 60,
                        ],
                    ],
                    'audio' => [
                        'audio_codec' => 'libopus',
                        'channels' => [
                            ['channels' => '2', 'audio_bitrate' => '128k'],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'hls-nvenc-h264-multi' => [
        'name' => 'HLS Streaming (NVENC H.264)',
        'description' => 'GPU-accelerated multi-bitrate HLS with NVENC H.264. Fast encoding.',
        'category' => 'gpu',
        'query' => [
            'outputs' => [
                [
                    'format' => 'hls',
                    'variants' => [
                        [
                            'video_codec' => 'h264_nvenc',
                            'width' => 1920,
                            'height' => 1080,
                            'nvenc_preset' => 'p5',
                            'nvenc_tune' => 'hq',
                            'nvenc_rc' => 'vbr',
                            'nvenc_cq' => 23,
                            'nvenc_h264_profile' => 'high',
                            'nvenc_spatial_aq' => true,
                            'nvenc_temporal_aq' => true,
                            'nvenc_b_frames' => 2,
                            'pixel_format' => 'yuv420p',
                            'maxrate' => '5000k',
                            'bufsize' => '10000k',
                            'gop_size' => 60,
                        ],
                        [
                            'video_codec' => 'h264_nvenc',
                            'width' => 1280,
                            'height' => 720,
                            'nvenc_preset' => 'p4',
                            'nvenc_tune' => 'hq',
                            'nvenc_rc' => 'vbr',
                            'nvenc_cq' => 25,
                            'nvenc_h264_profile' => 'main',
                            'nvenc_spatial_aq' => true,
                            'pixel_format' => 'yuv420p',
                            'maxrate' => '2500k',
                            'bufsize' => '5000k',
                            'gop_size' => 60,
                        ],
                        [
                            'video_codec' => 'h264_nvenc',
                            'width' => 854,
                            'height' => 480,
                            'nvenc_preset' => 'p4',
                            'nvenc_rc' => 'vbr',
                            'nvenc_cq' => 28,
                            'nvenc_h264_profile' => 'main',
                            'pixel_format' => 'yuv420p',
                            'maxrate' => '1200k',
                            'bufsize' => '2400k',
                            'gop_size' => 60,
                        ],
                    ],
                    'audio' => [
                        'audio_codec' => 'aac',
                        'channels' => [
                            ['channels' => '2', 'audio_bitrate' => '128k'],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'hls-nvenc-hevc-4k' => [
        'name' => 'HLS 4K (NVENC H.265)',
        'description' => 'GPU-accelerated 4K HEVC streaming. Fast 4K encoding with NVIDIA hardware.',
        'category' => 'gpu',
        'query' => [
            'outputs' => [
                [
                    'format' => 'hls',
                    'variants' => [
                        [
                            'video_codec' => 'hevc_nvenc',
                            'width' => 3840,
                            'height' => 2160,
                            'nvenc_preset' => 'p6',
                            'nvenc_tune' => 'hq',
                            'nvenc_rc' => 'vbr',
                            'nvenc_cq' => 22,
                            'nvenc_hevc_profile' => 'main10',
                            'nvenc_spatial_aq' => true,
                            'nvenc_temporal_aq' => true,
                            'nvenc_b_frames' => 2,
                            'pixel_format' => 'yuv420p10le',
                            'maxrate' => '12000k',
                            'bufsize' => '24000k',
                            'gop_size' => 60,
                        ],
                        [
                            'video_codec' => 'hevc_nvenc',
                            'width' => 1920,
                            'height' => 1080,
                            'nvenc_preset' => 'p5',
                            'nvenc_tune' => 'hq',
                            'nvenc_rc' => 'vbr',
                            'nvenc_cq' => 24,
                            'nvenc_hevc_profile' => 'main',
                            'nvenc_spatial_aq' => true,
                            'pixel_format' => 'yuv420p',
                            'maxrate' => '4500k',
                            'bufsize' => '9000k',
                            'gop_size' => 60,
                        ],
                        [
                            'video_codec' => 'hevc_nvenc',
                            'width' => 1280,
                            'height' => 720,
                            'nvenc_preset' => 'p4',
                            'nvenc_rc' => 'vbr',
                            'nvenc_cq' => 27,
                            'nvenc_hevc_profile' => 'main',
                            'pixel_format' => 'yuv420p',
                            'maxrate' => '2000k',
                            'bufsize' => '4000k',
                            'gop_size' => 60,
                        ],
                    ],
                    'audio' => [
                        'audio_codec' => 'aac',
                        'channels' => [
                            ['channels' => '6', 'audio_bitrate' => '256k'],
                            ['channels' => '2', 'audio_bitrate' => '128k'],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'mp4-nvenc-h264' => [
        'name' => 'MP4 Download (NVENC H.264)',
        'description' => 'GPU-accelerated 1080p MP4 for fast encoding and progressive download.',
        'category' => 'gpu',
        'query' => [
            'outputs' => [
                [
                    'format' => 'mp4',
                    'variants' => [
                        [
                            'video_codec' => 'h264_nvenc',
                            'width' => 1920,
                            'height' => 1080,
                            'nvenc_preset' => 'p6',
                            'nvenc_tune' => 'hq',
                            'nvenc_rc' => 'vbr',
                            'nvenc_cq' => 20,
                            'nvenc_h264_profile' => 'high',
                            'nvenc_spatial_aq' => true,
                            'nvenc_temporal_aq' => true,
                            'nvenc_b_frames' => 3,
                            'pixel_format' => 'yuv420p',
                            'faststart' => true,
                        ],
                    ],
                    'audio' => [
                        'audio_codec' => 'aac',
                        'channels' => [
                            ['channels' => '2', 'audio_bitrate' => '192k'],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'hls-h264-mobile' => [
        'name' => 'HLS Mobile-First (H.264)',
        'description' => 'Optimized for mobile devices with lower resolutions and bandwidth.',
        'category' => 'streaming',
        'query' => [
            'outputs' => [
                [
                    'format' => 'hls',
                    'variants' => [
                        [
                            'video_codec' => 'libx264',
                            'width' => 1280,
                            'height' => 720,
                            'crf' => 23,
                            'preset' => 'fast',
                            'video_profile' => 'main',
                            'level' => '3.1',
                            'pixel_format' => 'yuv420p',
                            'maxrate' => '2000k',
                            'bufsize' => '4000k',
                            'gop_size' => 60,
                        ],
                        [
                            'video_codec' => 'libx264',
                            'width' => 854,
                            'height' => 480,
                            'crf' => 25,
                            'preset' => 'fast',
                            'video_profile' => 'baseline',
                            'level' => '3.0',
                            'pixel_format' => 'yuv420p',
                            'maxrate' => '1000k',
                            'bufsize' => '2000k',
                            'gop_size' => 60,
                        ],
                        [
                            'video_codec' => 'libx264',
                            'width' => 640,
                            'height' => 360,
                            'crf' => 28,
                            'preset' => 'fast',
                            'video_profile' => 'baseline',
                            'level' => '3.0',
                            'pixel_format' => 'yuv420p',
                            'maxrate' => '600k',
                            'bufsize' => '1200k',
                            'gop_size' => 60,
                        ],
                    ],
                    'audio' => [
                        'audio_codec' => 'aac',
                        'channels' => [
                            ['channels' => '2', 'audio_bitrate' => '96k'],
                        ],
                    ],
                ],
            ],
        ],
    ],

];
