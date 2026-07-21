<?php

namespace App\Utils;

use Transliterator;

/**
 * Utility to manipulate strings.
 */
class StringUtils
{
    public static function slugify(string $value): string
    {
        $transliterator = Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
        $string = $transliterator->transliterate($value);

        return trim(
            preg_replace(
                '/[^a-z0-9]+/',
                '_',
                strtolower(trim(strip_tags($string)))
            ),
            '_'
        );
    }
}
