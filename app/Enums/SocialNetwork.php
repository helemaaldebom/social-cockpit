<?php

namespace App\Enums;

enum SocialNetwork: string
{
    case LinkedIn = 'linkedin';
    case Facebook = 'facebook';
    case Instagram = 'instagram';

    public function label(): string
    {
        return match($this) {
            self::LinkedIn => 'LinkedIn',
            self::Facebook => 'Facebook',
            self::Instagram => 'Instagram',
        };
    }
}
