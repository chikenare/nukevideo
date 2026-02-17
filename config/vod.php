<?php

return [
    /*
    |--------------------------------------------------------------------------
    | VOD Token Authentication Configuration
    |--------------------------------------------------------------------------
    |
    |
    */

    'token_secret' => env('VOD_TOKEN_SECRET', ''),

    'token_name' => env('VOD_TOKEN_NAME', '__hdnea__'),

    'token_window' => env('VOD_TOKEN_WINDOW', 3600),
];
