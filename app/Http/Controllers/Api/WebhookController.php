<?php

namespace App\Http\Controllers\Api;

use App\Enums\Chain;
use App\Http\Controllers\Controller;
use App\Services\Blockchain\Evm\EvmRpcClient;
use App\Services\Blockchain\IncomingTransfer;
use App\Services\DepositService;
use App\Support\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Inbound deposit webhooks (fast path — polling jobs remain the safety net).
 * Signature verified by middleware 'webhook.signature:{provider}'.
 *
 * Routes: POST /api/webhooks/{provider}
 */
final class WebhookController extends Controller
{
    public function __construct(
        private readonly DepositService $deposits,
        private readonly EvmRpcClient $rpc,
    ) {
    }

    public function __invoke(Request $request, string $provider): JsonResponse
    {
        $payload = (array) $request->json()->all();

        $processed = match ($provider) {
            'alchemy' => $this->handleAlchemy($payload),
            'custom' => $this->handleGeneric($payload),
            default => throw ValidationException::withMessages(['provider' => 'Unsupported provider.']),
        };

        return response()->json(['processed' => $processed]);
    }

    /** Alchemy "Address Activity" webhook payload. */
    private function handleAlchemy(array $payload): int
    {
        $activities = $payload['event']['activity'] ?? [];
        $networkMap = (array) config('webhooks.providers.alchemy.network_map', []);
        $latestByChain = [];
        $processed = 0;

        foreach ($activities as $activity) {
            $chain = Chain::tryFrom((string) ($networkMap[$activity['network'] ?? ''] ?? ''));
            if (! $chain || ! $chain->enabled()) {
                continue;
            }

            $toAddress = (string) ($activity['toAddress'] ?? '');
            $txHash = (string) ($activity['hash'] ?? '');
            if ($toAddress === '' || $txHash === '') {
                continue;
            }

            $latest = $latestByChain[$chain->value] ??= $this->rpc->blockNumber($chain);
            $blockNumber = (int) EvmRpcClient::hexToDec((string) ($activity['blockNum'] ?? '0x0'));
            $confirmations = $blockNumber > 0 ? max(0, $latest - $blockNumber + 1) : 0;

            $isToken = ($activity['category'] ?? '') === 'token';
            $asset = $isToken
                ? (string) ($activity['erc20Token']['symbol'] ?? '')
                : $chain->nativeAsset();
            if ($asset === '') {
                continue;
            }

            $decimals = $isToken
                ? (int) ($activity['erc20Token']['decimals'] ?? 18)
                : (int) $chain->config('decimals', 18);

            // Prefer the exact hex value; the decimal "value" field is a float
            // and only used as last resort.
            $rawHex = $activity['rawContract']['value'] ?? null;
            $amount = $rawHex
                ? Decimal::fromBaseUnits(EvmRpcClient::hexToDec((string) $rawHex), $decimals)
                : Decimal::norm(number_format((float) ($activity['value'] ?? 0), $decimals, '.', ''));

            $transfer = new IncomingTransfer(
                chain: $chain,
                asset: $asset,
                txHash: $txHash,
                logIndex: (int) EvmRpcClient::hexToDec((string) ($activity['logIndex'] ?? '0x0')),
                fromAddress: (string) ($activity['fromAddress'] ?? ''),
                toAddress: $toAddress,
                amount: $amount,
                blockNumber: $blockNumber > 0 ? $blockNumber : null,
                confirmations: $confirmations,
                raw: ['type' => 'webhook', 'provider' => 'alchemy'],
            );

            if ($this->deposits->detect($transfer)) {
                $processed++;
            }
        }

        return $processed;
    }

    /** Normalized payload for custom indexers — see config/webhooks.php. */
    private function handleGeneric(array $payload): int
    {
        $data = validator($payload, [
            'chain' => ['required', 'string'],
            'asset' => ['required', 'string', 'max:20'],
            'tx_hash' => ['required', 'string', 'max:128'],
            'to' => ['required', 'string', 'max:128'],
            'from' => ['nullable', 'string', 'max:128'],
            'amount' => ['required', 'string', 'regex:/^\d+(\.\d{1,18})?$/'],
            'log_index' => ['nullable', 'integer', 'min:0'],
            'block_number' => ['nullable', 'integer', 'min:0'],
            'confirmations' => ['nullable', 'integer', 'min:0'],
        ])->validate();

        $chain = Chain::tryFrom($data['chain']);
        if (! $chain || ! $chain->enabled()) {
            throw ValidationException::withMessages(['chain' => 'Unsupported or disabled chain.']);
        }

        $transfer = new IncomingTransfer(
            chain: $chain,
            asset: strtoupper($data['asset']),
            txHash: $data['tx_hash'],
            logIndex: (int) ($data['log_index'] ?? 0),
            fromAddress: $data['from'] ?? null,
            toAddress: $data['to'],
            amount: Decimal::norm($data['amount']),
            blockNumber: $data['block_number'] ?? null,
            confirmations: (int) ($data['confirmations'] ?? 0),
            raw: ['type' => 'webhook', 'provider' => 'custom'],
        );

        return $this->deposits->detect($transfer) ? 1 : 0;
    }
}
