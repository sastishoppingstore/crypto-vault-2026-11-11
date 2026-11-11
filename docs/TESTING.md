# Testing guide

## 1. Automated suite (local)

```bash
php artisan test                     # all 30 tests
php artisan test --filter=HdWallet   # one class
php artisan test --testsuite=Unit
```

The suite runs on **SQLite :memory:** with a fixed throwaway
`VAULT_MASTER_KEY` (see `phpunit.xml`) — no external services needed.

| Test file | Covers |
|---|---|
| `tests/Unit/HdWalletTest.php` | BIP39 seed (512-bit), BIP84 `bc1qcr8te…` vector, BIP44 EVM `0x9858Ef…` vector, TRON `TUEZSd…` vector, derivation paths, mnemonic word counts, hot-wallet address from private key |
| `tests/Unit/EncryptionServiceTest.php` | AES-256-GCM roundtrip, nonce randomization, tamper detection, wrong-key rejection, rotation (`reencrypt`), keyId stability, generated key format |
| `tests/Feature/SettingsServiceTest.php` | Dotted setting keys: exact lookup, nested lookup (`withdrawal.assets.USDT.fee`), defaults, overrides |
| `tests/Feature/Api/DepositCreditTest.php` | HD wallet provisioning → correct derived address, confirmation gating, **idempotent credit** (scanner retry = no double credit), reorg → `orphaned` never credited, unknown address ignored |
| `tests/Feature/Api/WithdrawalFlowTest.php` | Full lifecycle with `Http::fake()` Binance: request locks funds + idempotency key, approve → send → status 6 → completed, reject unlocks + cannot re-approve, permanent Binance error → failed + unlocked |

> These tests already caught real bugs during development (dotted settings
> lookup, null user status treated as frozen, `0x`-prefixed hot wallet key).
> Keep them green before every deploy.

### Re-running the raw crypto vectors outside PHPUnit

The original standalone verification scripts (if kept) mirror the unit tests
1:1 and are handy when upgrading `bitwasp/bitcoin`:

```bash
php /tmp/opencode/hd_check.php        # BIP84/BIP44/TRON vectors + base58 roundtrip
php /tmp/opencode/evm_sign_check.php  # EIP-155 canonical vector + EC recovery
```

## 2. Binance Spot testnet — what actually works

**Reality check (verified):** Binance’s public spot testnet
(`https://testnet.binance.vision`) supports `/api/v3/*` but its
`/sapi/v1/capital/*` endpoints (withdraw apply/history, deposit history,
deposit address) are **not usable** there. Do not point the withdrawal rail at
the spot testnet and expect real payout behaviour.

Recommended strategy:

| Goal | How |
|---|---|
| CI / local tests | `Http::fake()` — see the exact payloads used by `WithdrawalFlowTest` (withdraw apply → `{"id":…}`; history → `[{status: 6, txId:…}]`). This tests your state machine, not Binance. |
| Staging connectivity | Point at the testnet with `BINANCE_TESTNET=true`, call the **public** endpoints (`ping`, `systemStatus`) and the signed `/api/v3/account` to prove HMAC signing + clock sync. |
| Payout rehearsal | Binance doesn’t offer a signed-withdrawal sandbox. Rehearse on **production Binance with a tiny amount** (e.g. 1 USDT), from a key with withdrawals enabled + IP allow-list; verify `withdrawOrderId = vault-wd-{id}` appears in history. |
| Historical edge cases | Use `Http::fakeSequence()` to simulate: status 4 → 6, status 1 (failed), HTTP 429 rate limit (retryable → job retries), `-1021` timestamp drift (retryable). |

Example for a custom test:

```php
use Illuminate\Support\Facades\Http;

Http::fake([
    'testnet.binance.vision/sapi/v1/capital/withdraw/apply*'  => Http::response(['id' => 'wd-1']),
    'testnet.binance.vision/sapi/v1/capital/withdraw/history*' => Http::sequence()
        ->push([['id' => 'wd-1', 'status' => 4]])            // processing
        ->push([['id' => 'wd-1', 'status' => 6, 'txId' => '0xabc']]), // completed
]);
```

Remember: queue is `sync` under PHPUnit, so dispatched jobs run inline —
`Http::fake()` must be set **before** `approve()`/`request()`.

## 3. Chain testnets (deposit scanning end-to-end)

Set the vault chains to testnet and register a *dedicated* test wallet:

| Chain | Chain testnet | `.env` |
|---|---|---|
| ETH (Sepolia) | `https://rpc.sepolia.org` | `ETH_ENABLED=true`, `ETH_RPC_URL=https://rpc.sepolia.org` |
| BSC (Chapel) | `https://data-seed-prebsc-1-s1.binance.org:8545` | `BSC_ENABLED=true`, `BSC_RPC_URL=…prebsc…` |
| Polygon (Amoy) | `https://rpc-amoy.polygon.technology` | `POLYGON_ENABLED=true`, `POLYGON_RPC_URL=…amoy…` |
| BTC (Testnet3) | mempool.space `/testnet/api` | `BTC_ENABLED=true`, `BTC_TESTNET=true`, `BTC_API_URL=https://mempool.space/testnet/api` |
| TRON (Nile) | `https://nile.trongrid.io` | `TRON_ENABLED=true`, `TRON_API_URL=https://nile.trongrid.io` |

> EVM/Tron testnets intentionally keep mainnet derivation paths (standard
> practice). BTC testnet uses coin type 1 + `tb1q…` addresses.

### Manual deposit drill (EVM example)

```bash
# 1. API: register + get a testnet deposit address
curl -sX POST localhost:8000/api/v1/auth/register \
  -H 'Content-Type: application/json' \
  -d '{"name":"t","email":"t@example.com","password":"password1234","password_confirmation":"password1234"}'
# -> use the token from the response
curl -sX POST localhost:8000/api/v1/deposit/address \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"chain":"eth"}'
# -> {"address":"0x…", "chain":"eth", …}

# 2. Send testnet ETH from a faucet (Sepolia) to that address:
#    https://sepoliafaucet.com / https://faucet.sepolia.dev

# 3. Force a scan (don't wait for the scheduler) and watch the ledger
php artisan vault:scan-deposits --chain=eth
php artisan tinker --execute="App\Models\Deposit::latest()->first()->toArray();"
# repeat until confirmations >= deposits.confirmations.eth (12) -> credited
```

Balance push (with `reverb:start` running): subscribe `user.{id}` with
`resources/js/echo-example.js` and watch `balance.updated` arrive the moment
the credit commits. Confirmations can be lowered temporarily in
`/admin/settings` to make the drill quick.

### Manual withdrawal drill

```bash
# 1. Fund the balance (admin panel → user → Adjust balance, or API deposit)
# 2. User requests a withdrawal (2FA header required)
curl -sX POST localhost:8000/api/v1/withdraw/request \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -H "X-2FA-Code: $TOTP" \
  -d '{"asset":"USDT","amount":"50","to_address":"0x3535…","idempotency_key":"drill-1"}'
# -> pending_manual (amount above auto_limit) + funds locked

# 3. Admin panel (/admin/withdrawals) -> Review -> fresh 2FA -> Approve
#    WithdrawalService::process() runs via Horizon; watch status:
#    approved -> processing -> (Binance) -> completed
```

Reject-path check: request another withdrawal and reject it — the ledger must
show `withdrawal_unlock` and the user balance must be whole again.

### Webhook drill (optional path)

`POST /api/v1/webhooks/custom` with HMAC-SHA256 of the **raw body**, hex,
in the `X-Signature` header (`WEBHOOK_CUSTOM_SECRET` in `.env`):

```bash
BODY='{"chain":"eth","asset":"ETH","tx_hash":"0xdrill","log_index":0,"from":"0x1111…","to":"0xYOUR_DEPOSIT","amount":"0.01","block_number":1}'
SIG=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$WEBHOOK_CUSTOM_SECRET" -hex | awk '{print $2}')
curl -sX POST localhost:8000/api/v1/webhooks/custom \
  -H 'Content-Type: application/json' -H "X-Signature: $SIG" -d "$BODY"
```

Replaying the identical body must **not** create a second credit (idempotency
by `tx_hash`/`log_index`). A bad signature must return 401.

## 4. Security checks worth running before launch

```bash
# Admin actions without a fresh code must fail
curl -sX POST localhost:8000/admin/withdrawals/1/approve -d 'two_factor_code=000000'   # -> invalid code error

# Withdrawals are rate limited per user (throttle:withdrawals)
for i in $(seq 1 10); do curl -s -o /dev/null -w '%{http_code}\n' -X POST /api/v1/withdraw/request … ; done
# expect 429 after the burst

# Frozen user cannot withdraw (admin panel -> Freeze, then retry request) -> 422 "account is frozen"

# Vault key rotation smoke test
php artisan vault:reencrypt --old-key="base64:OLD" --force
php artisan test   # encryption + HD tests must still pass
```

## 5. What is intentionally NOT covered by the automated suite

- Live Binance payouts (see §2 — use the tiny-amount production rehearsal).
- Live chain RPC/scanner code paths (RPC/API calls are integration-tested per
  §3 with testnets; mock `EvmRpcClient` for pure unit tests if you extend).
- Reverb/Pusher delivery (covered by framework; use an Echo client smoke test
  in staging).
- Concurrency at the DB level beyond row-lock semantics on SQLite — run one
  load test on MySQL/Postgres (e.g. 50 parallel lock requests for the same
  balance; exactly one should succeed for the full amount).
