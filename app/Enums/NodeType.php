<?php

namespace App\Enums;

enum NodeType: string
{
    case WORKER = 'worker';
    case PROXY = 'proxy';
}
