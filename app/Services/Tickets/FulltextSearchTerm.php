<?php

declare(strict_types=1);

namespace App\Services\Tickets;

final class FulltextSearchTerm
{
    private const array OPERATORS = ['+', '-', '<', '>', '(', ')', '~', '*', '"', '@'];

    private const int MIN_PREFIX_LENGTH = 3;

    private const int MAX_TERMS = 10;

    public static function toBooleanMode(string $input): string
    {
        $words = preg_split('/\s+/u', trim(str_replace(self::OPERATORS, ' ', $input)), -1, PREG_SPLIT_NO_EMPTY);

        if ($words === false || $words === []) {
            return '';
        }

        return implode(' ', array_map(
            static fn (string $word): string => mb_strlen($word) >= self::MIN_PREFIX_LENGTH ? $word.'*' : $word,
            array_slice($words, 0, self::MAX_TERMS),
        ));
    }
}
