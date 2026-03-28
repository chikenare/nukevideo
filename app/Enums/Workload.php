<?php

namespace App\Enums;

enum Workload: string
{
    case LIGHT = 'light';
    case MEDIUM = 'medium';
    case HEAVY = 'heavy';
}
