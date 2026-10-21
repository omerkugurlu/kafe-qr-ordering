<?php

namespace App\Services;

final class Money
{
    public static function toCents(string|int|float|null $amount): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }

        if (is_int($amount)) {
            return $amount;
        }

        $normalized = str_replace(',', '.', (string) $amount);
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '0');

        $cents = ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        return $whole < 0 ? -abs($cents) : $cents;
    }

    public static function toDecimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function format(int $cents): string
    {
        return '₺'.number_format($cents / 100, 2, ',', '.');
    }

    public static function formatDecimal(string|int|float|null $amount): string
    {
        return self::format(self::toCents($amount));
    }
}
