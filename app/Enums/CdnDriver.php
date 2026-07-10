<?php

declare(strict_types=1);

namespace App\Enums;

enum CdnDriver: string
{
    case SelfHosted = 'self_hosted';
    case Bunny = 'bunny';
}
