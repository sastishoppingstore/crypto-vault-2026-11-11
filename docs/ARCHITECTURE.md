# Architecture

## 1. System diagram (text)

```
                         ┌──────────────────────────────────────────────────┐
                         │                    CLIENTS                        │
                         │   Mobile / Web app ── REST (Sanctum token)        │
                         │                    └─ WebSocket (Reverb/Pusher)   │
                         └───────────────┬──────────────────┬───────────────┘
                                         │ HTTPS            │ wss
                    ┌────────────────────▼───────┐   ┌──────▼─────────────────┐
                    │  nginx (TLS, rate limits)  │   │  Laravel Reverb :8080  │
                    └────────────┬───────────────┘   └──────▲─────────────────┘
                                 │ php-fpm                  │ broadcast()
        ┌────────────────────────▼──────────────────────────┴─────────────────┐
        │                         LARAVEL 11 APPLICATION                       │
        │                                                                      │
        │  API layer                Domain services             Admin panel    │
        │  ─────────                ────────────────            ───────────    │
        │  AuthController           WalletService (HD)          Blade+Tailwind │
        │  DepositAddressController HdWalletService             /admin         │
        │  BalanceController        DepositService              admin middleware│
        │  TransactionController    WithdrawalService           2FA per action │
        │  WithdrawalController     BalanceService (ledger)                    │
        │  TwoFactorController      BinanceService (signed)                    │
        │  Admin\* (API)            EvmSender / EvmTransactionSigner            │
        │                           EncryptionService (AES-GCM)                │
        │                           SettingsService / AuditLogger              │
        │                                                                      │
        │  Middleware: auth:sanctum · admin · admin.2fa · 2fa:<action> ·       │
        │              throttle:api|login|withdrawals · webhook.signature      │
        └───────┬───────────────────┬──────────────────┬──────────────────────┘
                │                   │                  │
        ┌───────▼───────┐   ┌───────▼───────┐   ┌──────▼─────────┐
        │  MySQL/PgSQL  │   │ Redis         │   │ Queues/Horizon │
        │  • users      │   │ • cache       │   │ • default      │
        │  • wallets    │   │ • locks/uniq  │   │ • deposits     │
        │  • deposits   │   │ • sessions    │   │ • withdrawals  │
        │  • withdrawals│   └───────────────┘   └──────┬─────────┘
        │  • balances   │                              │
        │  • transactions (append-only)                │
        │  • api_keys (encrypted)                      │
        │  • vault_secrets (encrypted seed/hot keys)   │
        │  • settings · admin_logs · sync_cursors      │
        └──────────────────────────────────────────────┘
                              │ jobs
        ┌─────────────────────┼───────────────────────────────┐
        │                     │                               │
 ┌──────▼───────┐   ┌─────────▼─────────┐            ┌────────▼─────────┐
 │ ScanChain-   │   │ ProcessWithdrawal │            │ SyncExchange-    │
 │ DepositsJob  │   │ Job               │            │ DepositsJob      │
 │ (per chain,  │   │ CheckWithdrawal-  │            │ (Binance         │
 │  every min)  │   │ StatusJob         │            │  deposit history)│
 └──────┬───────┘   └─────────┬─────────┘            └────────┬─────────┘
        │                     │                               │
 ┌──────▼─────────────────────▼───────────────────────────────▼─────────┐
 │                          External rails                              │
 │  EVM JSON-RPC (eth/bsc/polygon) · mempool.space (BTC) · TronGrid     │
 │  Binance Spot REST (HMAC) · HMAC webhooks (optional, inbound)        │
 └───────────────────────────────────────────────────────────────────────┘
```

## 2. Layer responsibilities

| Layer | Directory | Rule of thumb |
|---|---|---|
| HTTP | `app/Http/Controllers`, `app/Http/Middleware`, `app/Http/Requests` | Validate + authorize + delegate. No business logic, no DB writes. |
| Domain services | `app/Services` | All money logic. Every balance change goes through `BalanceService`. |
| Blockchain | `app/Services/Blockchain` | HD derivation, scanners, EVM signing/sending, address validation. |
| Exchange | `app/Services/Exchange` | Binance signed client + ExchangeManager (CCXT fallback). |
| Security | `app/Services/Security` | Encryption envelope, 2FA, audit log, settings. |
| Jobs/Events | `app/Jobs`, `app/Events` | Durable background work; broadcast = real-time fan-out. |
| Persistence | `app/Models`, `database/migrations` | Thin models; encrypted casts; append-only ledger. |

## 3. Wallet topology

```
      BIP39 mnemonic (24 words)  ── encrypted ──▶  vault_secrets.hd_seed_mainnet
                  │                                  (AES-256-GCM, VAULT_MASTER_KEY)
                  ▼
            BIP32 master key ──── derived on demand, never stored
                  │
   ┌──────────────┼──────────────────┬─────────────────────┐
   ▼              ▼                  ▼                     ▼
 m/84'/0'/0'/0/i  m/44'/60'/0'/0/i  m/44'/195'/0'/0/i    m/44'/60'/0'/0/i
 BTC (P2WPKH)     EVM chains         TRON                 hot wallet (separate
 per user index   ONE shared         per user index       vault_secret, single key
                  address/user                            for payouts)
```

- **Per-user deposit addresses.** `HdWalletService::allocateIndex()` row-locks the
  `hd.next_index.{chain}` settings row, so two requests can never derive the
  same address. EVM chains intentionally share one address per user
  (ETH/BSC/Polygon) so funds sent to the “wrong” EVM chain are recoverable.
- **Private keys never touch disk or DB.** `derive()` returns a `DerivedKey`
  used in-memory by scanners (never) and senders (EVM hot wallet only).
- **Testnet ports.** BTC testnet switches the address encoder + coin type;
  EVM/Tron testnets keep mainnet derivation paths (standard practice).

## 4. Deposit flow (polling scanner)

```
 scheduler (every minute)
   └─ ScanChainDepositsJob(chain)
        └─ ScannerManager::scannerFor(chain)
             └─ EvmScanner | BitcoinScanner | TronScanner
                  │  reads sync_cursors (last scanned block, reorg safety 5)
                  │  fetches blocks / address txs + ERC20 transfer logs
                  ▼
             IncomingTransfer DTO  (chain, tx_hash, log_index, to, amount, confs)
                  ▼
             DepositService::detect()
                  │  match to_address against wallets (case-insensitive)
                  │  upsert by UNIQUE(chain, tx_hash, log_index, asset)
                  │  status: detected → confirming → credited
                  │  reorg: block lost → orphaned (never credited again)
                  ▼
             DepositService::credit()  [DB transaction + SELECT FOR UPDATE]
                  │  re-check status (idempotent no matter how many retries)
                  ▼
             BalanceService::credit() → balances row + transactions ledger row
                  ▼
             DepositCredited event → broadcast user.{id} + admin.feed
```

Optional: `POST /api/v1/webhooks/{provider}` accepts push-style notifications
(HMAC-SHA256 verified, 90-second replay guard) and feeds the same
`DepositService`, so polling and webhooks never double-credit.

**Confirmation gate.** Required confirmations per chain come from
`settings.deposits.confirmations` (defaults seeded: BTC 3, ETH 12, BSC 15,
Polygon 128, Tron 19). `DepositService::refresh()` credits pending deposits as
the chain advances.

## 5. Withdrawal flow

```
 POST /withdraw/request   (auth:sanctum + throttle:withdrawals + 2FA header)
   │
   ├─ per-asset policy:  settings.withdrawal.assets.{ASSET}
   │     { enabled, rail: binance|onchain, min, fee, auto_limit }
   ├─ address validation for on-chain rails (AddressValidator)
   ├─ idempotency_key replay protection
   ▼
 WithdrawalService::request()  [transaction + row lock]
   ├─ INSERT withdrawal  (status pending_auto | pending_manual)
   └─ BalanceService::lockAmount(total = amount + fee)
        available -= total ; locked += total
   ▼
 ┌────────────────────── auto_limit reached? ──────────────────────┐
 │ ≤ auto_limit AND master switch AND user has 2FA + verified email │
 │            → pending_auto → ProcessWithdrawalJob                 │
 │ else                                                             │
 │            → pending_manual → admin panel approve/reject (2FA)   │
 └──────────────────────────────────────────────────────────────────┘
   │                    │
   │ approve            │ reject
   ▼                    ▼
 approved            rejected ──▶ BalanceService::releaseAmount()  (unlock)
   ▼
 ProcessWithdrawalJob → WithdrawalService::process()
   ├─ status → processing  (attempts++, provider set)
   ├─ rail = binance → BinanceService::withdraw(coin, address, amount,
   │                   network, tag, withdrawOrderId = vault-wd-{id})
   │                   (withdrawOrderId is Binance's idempotency key)
   └─ rail = onchain → EvmSender: nonce → gasPrice+15% → estimate+20% →
                       EIP-155 sign → eth_sendRawTransaction
   ▼
 CheckWithdrawalStatusJob (every minute)
   ├─ Binance withdrawHistory → status 6 → complete()
   ├─ EVM receipt.status==1 → complete() ; ==0 → markFailed()
   ├─ still pending → leave processing
   └─ retryable errors → job backoff [60s, 300s]; exhausted → failed
   ▼
 complete(): BalanceService::settleLock() → locked -= total  (funds gone)
 markFailed(): BalanceService::releaseAmount() → available += total (unlock)
```

State machine:

```
pending_auto ──▶ processing ──▶ completed
pending_manual ─approve─▶ approved ──▶ processing ──▶ completed
pending_manual ─reject─▶ rejected                 └──▶ failed
```

## 6. Ledger & money safety

- **One writer.** `BalanceService::apply()` is the only method that mutates
  `balances`. Every call runs in a DB transaction with `SELECT … FOR UPDATE`
  and appends exactly one `transactions` row (append-only audit trail).
- **No floats.** All amounts are 18-decimal strings via the `Decimal` BC-Math
  wrapper (`bcadd`/`bccomp` at scale 18), including in EVM calldata encoding.
- **Lock → settle/release.** Withdrawals freeze `amount + fee` at request time.
  Settlement removes from `locked`; rejection/failure returns it to
  `available`. A withdrawal can never be paid twice: status guard inside
  `process()` + queue `ShouldBeUnique` + rail-side idempotency key.
- **Idempotent deposits.** `UNIQUE(chain, tx_hash, log_index, asset)` + status
  re-check inside a row lock; re-crediting is structurally impossible.

## 7. Security model

| Concern | Mechanism |
|---|---|
| Encryption at rest | `EncryptionService` — AES-256-GCM, format `v1:{keyId}:{b64(nonce|tag|ct)}`, AAD binds version+keyId. Key from `VAULT_MASTER_KEY` via `EnvKeyProvider` (pluggable `KeyProviderInterface` for HSM/KMS). |
| Key rotation | New key → `php artisan vault:reencrypt --old-key=…` decrypt/re-encrypts every encrypted column (`vault_secrets`, `api_keys`, user 2FA data). KeyId in ciphertext detects stale data. |
| AuthN | Sanctum personal-access tokens; admin panel session + `admin` middleware; login throttled. |
| AuthZ | `role` (user/admin) + `status` (active/frozen/suspended). Frozen users can deposit but not withdraw. |
| 2FA | TOTP (google2fa). Mandatory for admins (`admin.2fa`); `2fa:<action>` middleware on sensitive API routes (withdrawal, 2FA disable); **fresh code required per admin approve/reject/balance action**; 90 s replay guard. |
| Rate limiting | `throttle:api` (per user), `throttle:login` (per IP+email), `throttle:withdrawals` (per user), plus nginx zones for `/api/v1/auth` and `/api/v1/withdraw/request`. |
| Webhooks | HMAC-SHA256 over the raw body + timestamp replay window (config/webhooks.php). |
| Audit | `admin_logs` append-only: actor, action, subject, old/new values, IP, UA. Every admin action, withdrawal transition and system failure is recorded. |
| Transport | TLS everywhere; Reverb behind nginx `/app` proxy; trusted proxies configurable. |
| Blast radius | Hot wallet holds a small float; deposit funds accumulate on derived addresses and are swept to cold storage (operational process, roadmap automation). |

## 8. Real-time (Reverb)

| Channel | Who | Events |
|---|---|---|
| `user.{id}` (private) | the user | `balance.updated`, `withdrawal.status`, `withdrawal.completed`, `withdrawal.failed` |
| `admin.feed` (private) | admins | `withdrawal.requested`, `withdrawal.status`, `deposit.credited` |

Auth: `POST /broadcasting/auth` with the Sanctum bearer token
(`routes/channels.php` authorizes ownership / role).
Client example: `resources/js/echo-example.js`.

## 9. Data model (core tables)

```
users ─┬─< wallets ──────────────< deposits >─┐
       ├─< balances                          │ unique(chain,tx_hash,log_index,asset)
       ├─< transactions (append-only ledger) │
       ├─< withdrawals ── approved_by/rejected_by → users
       └─< personal_access_tokens            │
api_keys (encrypted key/secret)              │
vault_secrets (encrypted: hd_seed, hot_wallet.evm)
settings (JSON values, Redis-cached)
admin_logs (audit trail)     sync_cursors (per-chain scanner state)
```

Money columns are `decimal(36,18)` (exact); all monetary strings are
normalized to 18 decimals by `Decimal::norm()` before storage.
