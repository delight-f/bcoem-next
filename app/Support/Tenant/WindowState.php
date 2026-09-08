<?php

declare(strict_types=1);

namespace App\Support\Tenant;

enum WindowState: int
{
    case Before = 0;
    case Open = 1;
    case After = 2;
}
