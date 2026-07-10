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
    | The video codec is set per output (the variants are one codec's ABR ladder,
    | varying only resolution/bitrate); different codecs go in different outputs.
    |
    */

    'hls-h264-multi' => [
        'name' => 'HLS (H.264)',
        'description' => 'Multi-bitrate HLS with H.264. Maximum device compatibility.',
        'category' => 'streaming',
        'query' => [
            'outputs' => [
                [
                    'video_codec' => 'libx264',
                    'variants' => [
                        [
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
                    'video_codec' => 'libx265',
                    'variants' => [
                        [
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

    'dash-av1-efficient' => [
        'name' => 'DASH AV1',
        'description' => 'AV1 encoding for maximum compression. Slower to encode but smallest files.',
        'category' => 'streaming',
        'query' => [
            'outputs' => [
                [
                    'video_codec' => 'libsvtav1',
                    'variants' => [
                        [
                            'width' => 1920,
                            'height' => 1080,
                            'svtav1_crf' => 30,
                            'svtav1_preset' => 6,
                            'pixel_format' => 'yuv420p',
                            'maxrate' => '3000k',
                            'bufsize' => '6000k',
                            'gop_size' => 60,
                            'target_vmaf' => 94,
                        ],
                        [
                            'width' => 1280,
                            'height' => 720,
                            'svtav1_crf' => 32,
                            'svtav1_preset' => 6,
                            'pixel_format' => 'yuv420p',
                            'maxrate' => '1500k',
                            'bufsize' => '3000k',
                            'gop_size' => 60,
                            'target_vmaf' => 93,
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

    'hls-h264-mobile' => [
        'name' => 'HLS Mobile-First (H.264)',
        'description' => 'Optimized for mobile devices with lower resolutions and bandwidth.',
        'category' => 'streaming',
        'query' => [
            'outputs' => [
                [
                    'video_codec' => 'libx264',
                    'variants' => [
                        [
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
