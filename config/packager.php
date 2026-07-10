<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Shaka Packager
    |--------------------------------------------------------------------------
    |
    | Static CMAF packaging: each output is packaged once into shared segments
    | that serve both HLS and DASH. `bin` is the packager executable (shipped in
    | the worker image); `segment_duration` is the target CMAF segment length in
    | seconds and should divide evenly into the encode GOP for clean boundaries.
    |
    */

    'bin' => env('PACKAGER_BIN', 'packager'),

    'segment_duration' => (int) env('PACKAGER_SEGMENT_DURATION', 10),

];
