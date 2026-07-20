<?php

namespace App\Enums;

enum Platform: string
{
    case Instagram = 'instagram';
    case X = 'x';
    case Tiktok = 'tiktok';
    case Youtube = 'youtube';

    /** Human-facing platform name (for user-visible messages). */
    public function label(): string
    {
        return match ($this) {
            self::Instagram => 'Instagram',
            self::X => 'X',
            self::Tiktok => 'TikTok',
            self::Youtube => 'YouTube',
        };
    }
}
