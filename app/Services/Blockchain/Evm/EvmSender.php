<?php

namespace App\Services\Blockchain\Evm;

use App\Enums\Chain;
use App\Exceptions\WithdrawalException;
use App\Models\VaultSecret;
use App\Services\Blockchain\HdWalletService;
use App\Services\Blockchain\OnChainSenderInterface;
use App\Support\Decimal;
use App\Support\Rlp;

/**
 * Sends native and ERC20/BEP20 withdrawals from the EVM hot wallet.
 *
 * The hot wallet private key lives encrypted in vault_secrets
 * ("hot_wallet.evm"). Keep only a small operational float there — cold
 * storage holds the bulk of user funds.
 *
 * Flow: nonce(pending) -> gasPrice (+15% bump) -> estimate gas (+20%) ->
 * sign (EIP-155) -> eth_sendRawTransaction.
 */
final class EvmSender implements OnChainSenderInterface
{
    private const ERC20_TRANSFER_SELECTOR = 'a9059cbb';
    private const GAS_PRICE_BUMP_NUM = 115;
    private const GAS_PRICE_BUMP_DEN = 100;
    private const GAS_ESTIMATE_BUMP_NUM = 120;
    private const GAS_ESTIMATE_BUMP_DEN = 100;

    public function __construct(
        private readonly EvmRpcClient $rpc,
        private readonly EvmTransactionSigner $signer,
        private readonly HdWalletService $hd,
    ) {
    }

    public function supports(Chain $chain): bool
    {
        return $chain->family() === 'evm';
    }

    public function send(Chain $chain, string $asset, string $to, string $amount): string
    {
        $token = $chain->token($asset);

        return $token
            ? $this->sendToken($chain, $token, $to, $amount)
            : $this->sendNative($chain, $to, $amount, $asset);
    }

    private function sendNative(Chain $chain, string $to, string $amount, string $asset): string
    {
        if (strcasecmp($asset, $chain->nativeAsset()) !== 0) {
            throw WithdrawalException::permanent("Asset [{$asset}] is not supported on {$chain->value}.");
        }

        $units = Decimal::toBaseUnits($amount, (int) $chain->config('decimals', 18));

        return $this->dispatch($chain, [
            'to' => $to,
            'value' => $units,
            'data' => '',
            'gasLimit' => '21000',
        ]);
    }

    /** @param  array{symbol: string, address: string, decimals: int}  $token */
    private function sendToken(Chain $chain, array $token, string $to, string $amount): string
    {
        $units = Decimal::toBaseUnits($amount, (int) $token['decimals']);
        $data = '0x'.self::ERC20_TRANSFER_SELECTOR
            .EvmRpcClient::padAddress($to)
            .EvmRpcClient::padUint($units);

        return $this->dispatch($chain, [
            'to' => $token['address'],
            'value' => '0',
            'data' => $data,
            'gasLimit' => null, // estimate
        ]);
    }

    /** @param  array{to: string, value: string, data: string, gasLimit: ?string}  $tx */
    private function dispatch(Chain $chain, array $tx): string
    {
        $privateKey = (string) VaultSecret::get((string) config('blockchain.hot_wallet.evm_secret_name', 'hot_wallet.evm'));
        $from = $this->hd->evmAddressFromPrivateKey($privateKey);

        $nonce = $this->rpc->nonce($chain, $from);
        $gasPrice = $this->bumpedGasPrice($chain);

        $gasLimit = $tx['gasLimit'] ?? $this->estimateGas($chain, $from, $tx);

        $signed = $this->signer->sign([
            'nonce' => $nonce,
            'gasPrice' => $gasPrice,
            'gasLimit' => $gasLimit,
            'to' => $tx['to'],
            'value' => $tx['value'],
            'data' => $tx['data'],
            'chainId' => (int) $chain->config('chain_id'),
        ], $privateKey);

        try {
            $hash = $this->rpc->sendRawTransaction($chain, $signed['raw']);
        } catch (\Throwable $e) {
            throw WithdrawalException::transient('eth_sendRawTransaction failed: '.$e->getMessage(), $e);
        }

        if (! preg_match('/^0x[0-9a-fA-F]{64}$/', $hash)) {
            throw WithdrawalException::transient("Node returned an invalid tx hash: {$hash}");
        }

        return $hash;
    }

    private function estimateGas(Chain $chain, string $from, array $tx): string
    {
        try {
            $estimate = $this->rpc->estimateGas($chain, [
                'from' => $from,
                'to' => $tx['to'],
                'value' => EvmRpcClient::decToHex($tx['value']),
                'data' => $tx['data'] !== '' ? $tx['data'] : '0x',
            ]);

            return gmp_strval(gmp_div_q(
                gmp_mul(gmp_init($estimate, 10), gmp_init(self::GAS_ESTIMATE_BUMP_NUM)),
                gmp_init(self::GAS_ESTIMATE_BUMP_DEN),
            ));
        } catch (\Throwable) {
            return '80000'; // safe fallback for a basic token transfer
        }
    }

    private function bumpedGasPrice(Chain $chain): string
    {
        $gasPrice = $this->rpc->gasPrice($chain);

        return gmp_strval(gmp_div_q(
            gmp_mul(gmp_init($gasPrice, 10), gmp_init(self::GAS_PRICE_BUMP_NUM)),
            gmp_init(self::GAS_PRICE_BUMP_DEN),
        ));
    }
}
