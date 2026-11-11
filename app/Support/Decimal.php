<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Exact decimal math for money-like values (BC Math wrapper, scale 18).
 * Never use floats for balances/amounts anywhere in this codebase.
 */
final class Decimal
{
    public const SCALE = 18;

    public static function add(string ...$values): string
    {
        $result = '0';
        foreach ($values as $v) {
            $result = bcadd($result, self::norm($v), self::SCALE);
        }

        return $result;
    }

    public static function sub(string $a, string $b): string
    {
        return bcsub(self::norm($a), self::norm($b), self::SCALE);
    }

    public static function mul(string $a, string $b): string
    {
        return bcmul(self::norm($a), self::norm($b), self::SCALE);
    }

    public static function div(string $a, string $b): string
    {
        if (bccomp(self::norm($b), '0') === 0) {
            throw new InvalidArgumentException('Division by zero.');
        }

        return bcdiv(self::norm($a), self::norm($b), self::SCALE);
    }

    /** Store/normalize a value to the full 18-decimal scale. */
    public static function norm(string $value): string
    {
        $value = trim($value);
        if ($value === '' || ! preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            throw new InvalidArgumentException("Not a valid decimal: '{$value}'.");
        }

        return bcadd($value, '0', self::SCALE);
    }

    public static function cmp(string $a, string $b): int
    {
        return bccomp(self::norm($a), self::norm($b), self::SCALE);
    }

    public static function isZero(string $a): bool
    {
        return self::cmp($a, '0') === 0;
    }

    public static function isPositive(string $a): bool
    {
        return self::cmp($a, '0') > 0;
    }

    public static function neg(string $a): string
    {
        $a = self::norm($a);

        return bccomp($a, '0') === 0 ? $a : bcmul($a, '-1', self::SCALE);
    }

    /** Convert a human amount to integer base units (e.g. 1.5 USDT -> 1500000 for 6 decimals). */
    public static function toBaseUnits(string $amount, int $decimals): string
    {
        $result = bcmul(self::norm($amount), bcpow('10', (string) $decimals), 0);

        return $result;
    }

    /** Convert integer base units to a human decimal string. */
    public static function fromBaseUnits(string $units, int $decimals): string
    {
        return bcdiv(self::norm($units), bcpow('10', (string) $decimals), $decimals > 18 ? 18 : $decimals);
    }

    /** Trim trailing zeros for display: 1.500000000000000000 -> 1.5 (display only). */
    public static function prettify(string $value, int $maxDecimals = 18): string
    {
        $value = bcadd(self::norm($value), '0', $maxDecimals);
        $value = rtrim(rtrim($value, '0'), '.');

        return $value === '' || $value === '-' ? '0' : $value;
    }
}
