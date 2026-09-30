<?php

namespace App\Enums;

enum NodeAction: string
{
    case Deploy = 'deploy';
    case Start = 'start';
    case Stop = 'stop';
}
