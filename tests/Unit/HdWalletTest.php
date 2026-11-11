<?php

namespace Tests\Unit;

use App\Enums\Chain;
use App\Services\Blockchain\HdWalletService;
use App\Services\Security\SettingsService;
use Tests\TestCase;

/**
 * HD address derivation verified against the canonical BIP39 test vector
 * (mnemonic "abandon ... about", empty passphrase):
 *
 *   BIP84  m/84'/0'/0'/0/0   -> bc1qcr8te4kr609gcawutmrza0j4xv80jy8z306fyu
 *   BIP44  m/44'/60'/0'/0/0  -> 0x9858EfFD232B4033E47d90003D41EC34EcaEda94  (EIP-55)
 *   BIP44  m/44'/195'/0'/0/0 -> TUEZSdKsoDHQMeZwihtdoBiN46zxhGWYdH           (TRON)
 *
 * If these tests ever fail, DO NOT ignore them: deposits would land on
 * addresses that the platform cannot derive again.
 */
final class HdWalletTest extends TestCase
{
    private const VECTOR_MNEMONIC = 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about';

    private HdWalletService $hd;

    protected function setUp(): void
    {
        parent::setUp();

        // SettingsService is only used by allocateIndex() (DB path), not here.
        $this->hd = new HdWalletService(new SettingsService());
    }

    public function test_seed_is_512_bits_for_a_valid_mnemonic(): void
    {
        $seed = $this->hd->seedFromMnemonic(self::VECTOR_MNEMONIC);

        $this->assertSame(64, strlen($seed->getBinary()), 'BIP39 seed must be 512 bits.');
    }

    public function test_bip84_bitcoin_native_segwit_address_matches_vector(): void
    {
        $key = $this->hd->masterKey(self::VECTOR_MNEMONIC)->derivePath("84'/0'/0'/0/0");

        $this->assertSame(
            'bc1qcr8te4kr609gcawutmrza0j4xv80jy8z306fyu',
            $this->hd->bitcoinAddress($key),
        );
    }

    public function test_bip44_evm_address_matches_vector_with_eip55_checksum(): void
    {
        $key = $this->hd->masterKey(self::VECTOR_MNEMONIC)->derivePath("44'/60'/0'/0/0");

        $this->assertSame(
            '0x9858EfFD232B4033E47d90003D41EC34EcaEda94',
            $this->hd->evmAddress($key->getPublicKey()),
        );
    }

    public function test_bip44_tron_address_matches_vector(): void
    {
        $key = $this->hd->masterKey(self::VECTOR_MNEMONIC)->derivePath("44'/195'/0'/0/0");

        $address = $this->hd->tronAddress($key->getPublicKey());

        $this->assertSame('TUEZSdKsoDHQMeZwihtdoBiN46zxhGWYdH', $address);
        $this->assertSame(34, strlen($address));
        $this->assertStringStartsWith('T', $address);
    }

    public function test_derivation_paths_follow_bip44_and_bip84_schemes(): void
    {
        $this->assertSame("84'/0'/0'/0/0", $this->hd->pathFor(Chain::Btc, 0));
        $this->assertSame("84'/0'/0'/0/7", $this->hd->pathFor(Chain::Btc, 7));
        $this->assertSame("44'/60'/0'/0/3", $this->hd->pathFor(Chain::Eth, 3));
        $this->assertSame("44'/60'/0'/0/3", $this->hd->pathFor(Chain::Bsc, 3));
        $this->assertSame("44'/195'/0'/0/1", $this->hd->pathFor(Chain::Tron, 1));
    }

    public function test_mnemonic_generation_produces_valid_word_counts(): void
    {
        $this->assertCount(12, explode(' ', $this->hd->generateMnemonic(128)));
        $this->assertCount(24, explode(' ', $this->hd->generateMnemonic(256)));
    }

    public function test_hot_wallet_address_derivation_from_private_key(): void
    {
        // EIP-155 spec example private key: 0x4646...46.
        $address = $this->hd->evmAddressFromPrivateKey(
            '0x4646464646464646464646464646464646464646464646464646464646464646'
        );

        $this->assertSame('0x9d8A62f656a8d1615C1294fd71e9CFb3E4855A4F', $address);
    }
}
