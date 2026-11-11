<?php

namespace App\Services\Blockchain;

use App\Enums\Chain;
use App\Models\Setting;
use App\Models\VaultSecret;
use App\Services\Security\SettingsService;
use BitWasp\Bitcoin\Address\SegwitAddress;
use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Crypto\EcAdapter\Key\PublicKeyInterface;
use BitWasp\Bitcoin\Key\Deterministic\HierarchicalKey;
use BitWasp\Bitcoin\Key\Deterministic\HierarchicalKeyFactory;
use BitWasp\Bitcoin\Key\PrivateKeyFactory;
use BitWasp\Bitcoin\Mnemonic\Bip39\Bip39SeedGenerator;
use BitWasp\Bitcoin\Mnemonic\MnemonicFactory;
use BitWasp\Bitcoin\Network\NetworkFactory;
use BitWasp\Bitcoin\Script\WitnessProgram;
use BitWasp\Buffertools\BufferInterface;
use Illuminate\Support\Facades\DB;
use kornrunner\Keccak;

/**
 * BIP39/BIP32/BIP44 HD wallet engine (secp256k1).
 *
 *  - One BIP39 mnemonic per environment, stored encrypted (vault_secrets).
 *  - Deposit addresses are derived on demand; private keys are never stored.
 *  - BTC: native segwit P2WPKH (BIP84). EVM chains share address index 60.
 *    TRON uses coin type 195 (base58check of 0x41 || keccak(pub)[12:]).
 *
 * Verified against the canonical BIP84 and BIP44 test vectors
 * (tests/Unit/HdWalletTest.php).
 */
final class HdWalletService
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    /** Generate a new BIP39 mnemonic (default 256 bits = 24 words). */
    public function generateMnemonic(int $bits = 256): string
    {
        return MnemonicFactory::bip39()->create($bits);
    }

    public function seedFromMnemonic(string $mnemonic): BufferInterface
    {
        return (new Bip39SeedGenerator())->getSeed(
            $mnemonic,
            (string) config('blockchain.seed_passphrase', ''),
        );
    }

    public function masterKey(string $mnemonic): HierarchicalKey
    {
        return HierarchicalKeyFactory::fromEntropy($this->seedFromMnemonic($mnemonic), Bitcoin::getEcAdapter());
    }

    /** Current mnemonic from the encrypted vault. */
    public function mnemonic(): string
    {
        return (string) VaultSecret::get((string) config('blockchain.seed_secret', 'hd_seed_mainnet'));
    }

    /** Derive address + key material for a chain and address index. */
    public function derive(Chain $chain, int $index): DerivedKey
    {
        $path = $this->pathFor($chain, $index);
        $key = $this->masterKey($this->mnemonic())->derivePath($path);

        $address = match ($chain->family()) {
            'bitcoin' => $this->bitcoinAddress($key, (bool) $chain->config('testnet', false)),
            'evm' => $this->evmAddress($key->getPublicKey()),
            'tron' => $this->tronAddress($key->getPublicKey()),
            default => throw new \RuntimeException("Unsupported chain family: {$chain->family()}"),
        };

        return new DerivedKey(
            address: $address,
            path: $path,
            privateKeyHex: $key->getPrivateKey()->getHex(),
            publicKeyHex: $key->getPublicKey()->getHex(),
        );
    }

    public function pathFor(Chain $chain, int $index): string
    {
        return rtrim((string) $chain->config('derivation_path'), '/')."/{$index}";
    }

    /**
     * Atomically allocate the next address index for a chain.
     * The settings row is row-locked so concurrent requests can never
     * derive the same address twice.
     */
    public function allocateIndex(Chain $chain, int $attempt = 0): int
    {
        try {
            $index = DB::transaction(function () use ($chain): int {
                $key = "hd.next_index.{$chain->value}";
                $row = Setting::query()->where('key', $key)->lockForUpdate()->first();

                if (! $row) {
                    Setting::query()->create([
                        'key' => $key,
                        'value' => 1,
                        'group' => 'hd',
                        'type' => 'int',
                        'description' => "Next derivation index for {$chain->value}",
                    ]);

                    return 0;
                }

                $current = (int) $row->value;
                $row->value = $current + 1;
                $row->save();

                return $current;
            });

            $this->settings->flush();

            return $index;
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Two workers raced on the very first allocation — retry.
            if ($attempt >= 2) {
                throw $e;
            }

            return $this->allocateIndex($chain, $attempt + 1);
        }
    }

    // ------------------------------------------------------------- address builders

    /** BIP84 native segwit (P2WPKH, bech32). */
    public function bitcoinAddress(HierarchicalKey $key, bool $testnet = false): string
    {
        $program = WitnessProgram::v0($key->getPublicKey()->getPubKeyHash());
        $address = new SegwitAddress($program);

        return $address->getAddress($testnet ? NetworkFactory::bitcoinTestnet() : null);
    }

    /** EIP-55 checksummed Ethereum-style address from a compressed public key. */
    public function evmAddress(PublicKeyInterface $publicKey): string
    {
        $point = $publicKey->getPoint();
        $x = str_pad(gmp_strval($point->getX(), 16), 64, '0', STR_PAD_LEFT);
        $y = str_pad(gmp_strval($point->getY(), 16), 64, '0', STR_PAD_LEFT);

        $hash = Keccak::hash(hex2bin($x.$y), 256);
        $lower = substr($hash, 24);

        $checksumHash = Keccak::hash($lower, 256);
        $out = '';
        for ($i = 0; $i < 40; $i++) {
            $out .= hexdec($checksumHash[$i]) > 7 ? strtoupper($lower[$i]) : $lower[$i];
        }

        return '0x'.$out;
    }

    /** Derive the address that controls a raw private key (hot wallet). */
    public function evmAddressFromPrivateKey(string $privateKeyHex): string
    {
        $privateKeyHex = str_starts_with($privateKeyHex, '0x') ? substr($privateKeyHex, 2) : $privateKeyHex;

        $privateKey = PrivateKeyFactory::fromHex($privateKeyHex, true, Bitcoin::getEcAdapter());

        return $this->evmAddress($privateKey->getPublicKey());
    }

    /** TRON base58check address: 0x41 || last 20 bytes of keccak256(pub). */
    public function tronAddress(PublicKeyInterface $publicKey): string
    {
        $point = $publicKey->getPoint();
        $x = str_pad(gmp_strval($point->getX(), 16), 64, '0', STR_PAD_LEFT);
        $y = str_pad(gmp_strval($point->getY(), 16), 64, '0', STR_PAD_LEFT);

        $hash = Keccak::hash(hex2bin($x.$y), 256);
        $payload = '41'.substr($hash, 24);

        return \BitWasp\Bitcoin\Base58::encodeCheck(\BitWasp\Buffertools\Buffer::hex($payload));
    }
}
