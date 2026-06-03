<?php

namespace App\Services;

class NumberNormalizer
{
    public static function mobile(?string $number): ?string
    {
        if (! $number) {
            return null;
        }

        $number = str_replace([' ', '-', '+'], '', trim($number));
        $number = preg_replace('/[^0-9]/', '', $number);

        if (str_starts_with($number, '880')) {
            return '0' . substr($number, 3);
        }

        if (str_starts_with($number, '80') && strlen($number) === 12) {
            return '0' . substr($number, 2);
        }

        return $number;
    }
}
