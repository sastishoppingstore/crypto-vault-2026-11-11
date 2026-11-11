<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Minimal Recursive Length Prefix (RLP) encoder as specified in the
 * Ethereum Yellow Paper. Used to build raw legacy (type-0, EIP-155)
 * transactions for signing.
 *
 * Encoding helpers return binary strings; callers concatenate them.
 */
final class Rlp
{
    /** Encode raw binary bytes. */
    public static function encodeBytes(string $bytes): string
    {
        if (strlen($bytes) === 1 && ord($bytes[0]) < 0x80) {
            return $bytes;
        }

        return self::lengthPrefix(strlen($bytes), 0x80, 0xB7).$bytes;
    }

    /** Encode a hex string (0x optional). Empty -> empty byte string. */
    public static function encodeHex(string $hex): string
    {
        return self::encodeBytes(self::hexToBytes($hex));
    }

    /** Encode an unsigned integer as minimal big-endian bytes. Zero -> empty. */
    public static function encodeInt(\GMP|int|string $n): string
    {
        if (! $n instanceof \GMP) {
            $n = gmp_init((string) $n, 10);
        }

        if (gmp_cmp($n, 0) === 0) {
            return chr(0x80);
        }

        return self::encodeBytes(self::intToBytes($n));
    }

    /** Encode a list from already-encoded binary items. */
    public static function encodeList(array $encodedItems): string
    {
        $body = implode('', $encodedItems);

        return self::lengthPrefix(strlen($body), 0xC0, 0xF7).$body;
    }

    private static function lengthPrefix(int $len, int $shortBase, int $longBase): string
    {
        if ($len <= 55) {
            return chr($shortBase + $len);
        }

        $lenBytes = self::intToBytes(gmp_init((string) $len, 10));

        return chr($longBase + strlen($lenBytes)).$lenBytes;
    }

    /** GMP -> minimal big-endian binary (zero -> empty string). */
    public static function intToBytes(\GMP $n): string
    {
        if (gmp_cmp($n, 0) === 0) {
            return '';
        }

        $hex = gmp_strval($n, 16);
        if (strlen($hex) % 2 === 1) {
            $hex = '0'.$hex;
        }

        return (string) hex2bin($hex);
    }

    /** Hex (0x optional) -> binary. */
    public static function hexToBytes(string $hex): string
    {
        $hex = str_starts_with($hex, '0x') || str_starts_with($hex, '0X')
            ? substr($hex, 2)
            : $hex;

        if ($hex === '') {
            return '';
        }
        if (! ctype_xdigit($hex)) {
            throw new InvalidArgumentException("Invalid hex string: '{$hex}'.");
        }
        if (strlen($hex) % 2 === 1) {
            $hex = '0'.$hex;
        }

        return (string) hex2bin($hex);
    }

    public static function bytesToHex(string $bytes): string
    {
        return '0x'.bin2hex($bytes);
    }
}
