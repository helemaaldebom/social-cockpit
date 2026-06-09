<?php

namespace App\Enums;

enum SourceCategory: string
{
    case AI = 'ai';
    case Webdev = 'webdev';
    case Marketing = 'marketing';
    case Conversie = 'conversie';
    case SEO = 'seo';

    public function label(): string
    {
        return match($this) {
            self::AI => 'AI',
            self::Webdev => 'Webdevelopment',
            self::Marketing => 'Online Marketing',
            self::Conversie => 'Conversieoptimalisatie',
            self::SEO => 'SEO',
        };
    }
}
