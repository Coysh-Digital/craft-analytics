<?php

namespace coyshdigital\craftanalytics\enums;

enum DeviceType: int
{
    case Unknown = 0;
    case Desktop = 1;
    case Mobile = 2;
    case Tablet = 3;

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Unknown',
            self::Desktop => 'Desktop',
            self::Mobile => 'Mobile',
            self::Tablet => 'Tablet',
        };
    }
}
