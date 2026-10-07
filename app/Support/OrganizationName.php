<?php

namespace App\Support;

use Normalizer;

final class OrganizationName
{
    public static function display(string $name): string
    {
        return Normalizer::normalize(preg_replace('/\s+/u', ' ', trim($name)), Normalizer::FORM_C);
    }

    public static function key(string $name): string
    {
        return mb_strtolower(self::display($name), 'UTF-8');
    }
}
