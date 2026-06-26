<?php

namespace App\Enums;

enum OutputFormat: string
{
    case HLS = 'hls';
    case DASH = 'dash';
}
