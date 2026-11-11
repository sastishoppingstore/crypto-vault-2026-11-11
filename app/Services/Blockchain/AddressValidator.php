<?php

namespace App\Services\Blockchain;

use App\Enums\Chain;
use BitWasp\Bitcoin\Address\AddressFactory;
use BitWasp\Bitcoin\Base58;
use BitWasp\Bitcoin\Network\NetworkFactory;

/**
 * Validates destination addresses before a withdrawal is accepted.
 * A wrong address means lost funds — this is the last safety net.
 */
final class AddressValidator
{
    public function validate(Chain $chain, string $address): bool
    {
        return match ($chain->family()) {
            'evm' => $this->isEvm($address),
            'bitcoin' => $this->isBitcoin($address, (bool) $chain->config('testnet', false)),
            'tron' => $this->isTron($address),
            default => false,
        };
    }

    public function assertValid(Chain $chain, string $address): void
    {
        if (! $this->validate($chain, $address)) {
            throw new \InvalidArgumentException("Invalid {$chain->label()} address.");
        }
    }

    /** 0x + 40 hex chars; verifies EIP-55 checksum when mixed case. */
    public function isEvm(string $address): bool
    {
        if (! preg_match('/^0x[0-9a-fA-F]{40}$/', $address)) {
            return false;
        }

        $body = substr($address, 2);
        if ($body === strtolower($body) || $body === strtoupper($body)) {
            return true; // all-lower / all-upper is acceptable
        }

        return $this->eip55($body) === $body;
    }

    /** Returns the EIP-55 checksummed form of a 40-char lowercase hex address. */
    public function eip55(string $lowerHex): string
    {
        $lowerHex = strtolower($lowerHex);
        $hash = \kornrunner\Keccak::hash($lowerHex, 256);
        $out = '';
        for ($i = 0; $i < 40; $i++) {
            $out .= hexdec($hash[$i]) > 7 ? strtoupper($lowerHex[$i]) : $lowerHex[$i];
        }

        return $out;
    }

    /** Bech32 (bc1/tb1) or legacy base58 addresses via bitwasp address factory. */
    public function isBitcoin(string $address, bool $testnet = false): bool
    {
        try {
            $network = $testnet ? NetworkFactory::bitcoinTestnet() : null;
            AddressFactory::fromString($address, $network);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** TRON base58 address: 25 bytes decoded, 0x41 version byte. */
    public function isTron(string $address): bool
    {
        if (! preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $address)) {
            return false;
        }

        try {
            return str_starts_with(Base58::decodeCheck($address)->getHex(), '41');
        } catch (\Throwable) {
            return false;
        }
    }

    /** TRON base58 -> 41-prefixed hex (TronGrid returns hex addresses). */
    public function tronToHex(string $address): ?string
    {
        try {
            return '0x'.Base58::decodeCheck($address)->getHex();
        } catch (\Throwable) {
            return null;
        }
    }

    /** 41-prefixed hex -> TRON base58. */
    public function tronFromHex(string $hex): ?string
    {
        try {
            $hex = str_starts_with($hex, '0x') ? substr($hex, 2) : $hex;
            if (! str_starts_with($hex, '41') || strlen($hex) !== 42) {
                return null;
            }

            return Base58::encodeCheck(\BitWasp\Buffertools\Buffer::hex($hex));
        } catch (\Throwable) {
            return null;
        }
    }

    /** Case-insensitive equality (EVM hex vs base58 etc. per family). */
    public function same(Chain $chain, string $a, string $b): bool
    {
        return match ($chain->family()) {
            'evm' => strtolower($a) === strtolower($b),
            'tron' => $this->tronToHex($a) === $this->tronToHex($b),
            default => $a === $b,
        };
    }
}
