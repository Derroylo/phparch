<?php

declare(strict_types=1);

namespace PhpArch\Enum;

enum Verbosity: int
{
    case NONE = 0;
    case VERBOSE = 1;
    case VERY_VERBOSE = 2;
    case DEBUG = 3;
}