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
                            'resolution' => '1080',
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
                            'resolution' => '720',
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
                            'resolution' => '480',
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
                            'resolution' => '2160',
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
                            'resolution' => '1080',
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
                            'resolution' => '720',
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

    'dash-vp9' => [
        'name' => 'DASH VP9 (Web Optimized)',
        'description' => 'Royalty-free DASH streaming with VP9 and Opus. Great for browsers.',
        'category' => 'streaming',
        'query' => [
            'outputs' => [
                [
                    'format' => 'dash',
                    'variants' => [
                        [
                            'video_codec' => 'libvpx-vp9',
                            'resolution' => '1080',
                            'vp9_crf' => 31,
                            'vp9_quality' => 'good',
                            'vp9_cpu_used' => 2,
                            'vp9_row_mt' => true,
                            'vp9_tile_columns' => '2',
                            'pixel_format' => 'yuv420p',
                            'maxrate' => '4000k',
                            'bufsize' => '8000k',
                            'gop_size' => 60,
                        ],
                        [
                            'video_codec' => 'libvpx-vp9',
                            'resolution' => '720',
                            'vp9_crf' => 33,
                            'vp9_quality' => 'good',
                            'vp9_cpu_used' => 2,
                            'vp9_row_mt' => true,
                            'vp9_tile_columns' => '1',
                            'pixel_format' => 'yuv420p',
                            'maxrate' => '2000k',
                            'bufsize' => '4000k',
                            'gop_size' => 60,
                        ],
                        [
                            'video_codec' => 'libvpx-vp9',
                            'resolution' => '480',
                            'vp9_crf' => 36,
                            'vp9_quality' => 'good',
                            'vp9_cpu_used' => 3,
                            'vp9_row_mt' => true,
                            'vp9_tile_columns' => '0',
                            'pixel_format' => 'yuv420p',
                            'maxrate' => '1000k',
                            'bufsize' => '2000k',
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
                            'resolution' => '1080',
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
                            'resolution' => '1080',
                            'svtav1_crf' => 30,
                            'svtav1_preset' => 6,
                            'pixel_format' => 'yuv420p',
                            'maxrate' => '3000k',
                            'bufsize' => '6000k',
                            'gop_size' => 60,
                        ],
                        [
                            'video_codec' => 'libsvtav1',
                            'resolution' => '720',
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
                            'resolution' => '720',
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
                            'resolution' => '480',
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
                            'resolution' => '360',
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
