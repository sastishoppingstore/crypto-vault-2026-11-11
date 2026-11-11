<?php

namespace App\Services\Blockchain\Evm;

use App\Support\Rlp;
use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Key\PrivateKeyFactory;
use BitWasp\Buffertools\Buffer;
use InvalidArgumentException;
use kornrunner\Keccak;

/**
 * Signs raw legacy (type-0) EVM transactions per EIP-155:
 *
 *   unsigned = rlp([nonce, gasPrice, gasLimit, to, value, data, chainId, 0, 0])
 *   sighash  = keccak256(unsigned)
 *   v        = recoveryId + chainId * 2 + 35
 *   signed   = rlp([nonce, gasPrice, gasLimit, to, value, data, v, r, s])
 *
 * s is normalized to the lower half of the curve order (EIP-2) and the
 * recovery id is flipped accordingly.
 *
 * Values are passed as decimal strings (wei) or GMP to avoid float errors.
 */
final class EvmTransactionSigner
{
    /**
     * @param  array{nonce: string, gasPrice: string, gasLimit: string, to: string, value: string, data?: string, chainId: int}  $tx
     * @param  string  $privateKeyHex  32-byte hex, 0x optional
     * @return array{raw: string, hash: string, v: string, r: string, s: string, recoveryId: int}
     */
    public function sign(array $tx, string $privateKeyHex): array
    {
        $privateKeyHex = str_starts_with($privateKeyHex, '0x') ? substr($privateKeyHex, 2) : $privateKeyHex;
        if (! preg_match('/^[0-9a-fA-F]{64}$/', $privateKeyHex)) {
            throw new InvalidArgumentException('Private key must be 32 bytes of hex.');
        }
        if (! preg_match('/^(0x)?[0-9a-fA-F]{40}$/', $tx['to'])) {
            throw new InvalidArgumentException('Invalid "to" address.');
        }

        $ec = Bitcoin::getEcAdapter();

        $base = [
            Rlp::encodeInt($tx['nonce']),
            Rlp::encodeInt($tx['gasPrice']),
            Rlp::encodeInt($tx['gasLimit']),
            Rlp::encodeHex($tx['to']),
            Rlp::encodeInt($tx['value']),
            Rlp::encodeHex($tx['data'] ?? ''),
        ];

        $chainId = (int) $tx['chainId'];
        $unsigned = Rlp::encodeList(array_merge($base, [
            Rlp::encodeInt($chainId),
            Rlp::encodeInt(0),
            Rlp::encodeInt(0),
        ]));

        $hashBin = Keccak::hash($unsigned, 256, true);

        $privateKey = PrivateKeyFactory::fromHex($privateKeyHex, true, $ec);
        $signature = $ec->signCompact(new Buffer($hashBin), $privateKey);

        $r = $signature->getR();
        $s = $signature->getS();
        $recoveryId = (int) $signature->getRecoveryId();

        // EIP-2: enforce low-s. Flip recovery id when s is replaced by n - s.
        $order = $ec->getGenerator()->getOrder();
        $halfOrder = gmp_div_q($order, gmp_init(2));
        if (gmp_cmp($s, $halfOrder) > 0) {
            $s = gmp_sub($order, $s);
            $recoveryId ^= 1;
        }

        $v = gmp_add(gmp_add(gmp_init($recoveryId), gmp_init(35)), gmp_mul(gmp_init($chainId), gmp_init(2)));

        $signed = Rlp::encodeList(array_merge($base, [
            Rlp::encodeInt($v),
            Rlp::encodeBytes(Rlp::intToBytes($r)),
            Rlp::encodeBytes(Rlp::intToBytes($s)),
        ]));

        return [
            'raw' => '0x'.bin2hex($signed),
            'hash' => '0x'.bin2hex($hashBin),
            'v' => gmp_strval($v, 10),
            'r' => gmp_strval($r, 16),
            's' => gmp_strval($s, 16),
            'recoveryId' => $recoveryId,
        ];
    }
}
