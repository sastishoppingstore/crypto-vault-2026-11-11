# Crypto Vault

A production-oriented crypto wallet/vault platform on **Laravel 11**:
per-user HD deposit addresses, on-chain deposit monitoring, exchange (Binance)
withdrawals, an internal ledger, real-time balance push (Reverb), a fully
audited admin panel and a security-first design.

> ⚠️ **Laravel 11 is past its security-support window (EOL March 2026).**
> The codebase deliberately avoids Laravel-11-only APIs; upgrade with
> `composer require laravel/framework:^12.0 && php artisan config:clear`
> (see `docs/DEPLOYMENT.md` → “Framework upgrade”).

---

## Architecture at a glance

```
 Clients ── REST /api/v1 ──▶ Laravel API (Sanctum tokens + TOTP)
        └─ WebSocket ──────▶ Reverb  ◀── broadcast events (balance, withdrawals)
                              ▲
                              │
      ┌───────────┬───────────┴───────────┬──────────────┐
      │ Deposit   │ Withdrawal            │ Treasury     │ Admin
      │ scanners  │ pipeline              │ sync         │ panel (Blade)
      │ (queue)   │ (queue)               │ (queue)      │ /admin
      └─────┬─────┘     ┌─────────────────┤              │
            │           │                 │              │
   EVM RPC / BTC API / TRON API    Binance native REST   MySQL/Redis
```

Full diagrams, flows and the security model: **[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)**.

## Feature highlights

| Area | What you get |
|---|---|
| HD wallets | BIP39/BIP32/BIP84 (BTC segwit), BIP44 (EVM `m/44'/60'/…`, TRON `m/44'/195'/…`); keys derived on demand, **never stored** |
| Deposits | Polling scanners for EVM chains, Bitcoin (mempool.space) and TRON + optional HMAC webhooks; confirmation gating; idempotent credit (unique index + row locks); reorg handling |
| Withdrawals | Rails: Binance native REST or EVM hot wallet (EIP-155 signing); per-asset policy (min/fee/auto-limit); automatic small payouts, manual admin approval for the rest; funds locked at request, unlocked on reject/failure |
| Ledger | Append-only `transactions` table, single mutation point (`BalanceService`) with `SELECT … FOR UPDATE`, 18-decimal BC Math (no floats, ever) |
| Real-time | Reverb (Pusher protocol): `user.{id}` channel for balances + withdrawal status, `admin.feed` for admins |
| Security | AES-256-GCM envelope encryption (key rotation via `vault:reencrypt`), TOTP 2FA (API + admin, per-action confirmation), rate limiting, webhook HMAC + replay guard, full audit log, frozen states, idempotency keys |
| Admin panel | Blade + Tailwind: dashboard, withdrawal approve/reject (per-action 2FA), deposits, users (balance adjust / freeze), settings, audit log |

## Requirements

- PHP **8.3+** with `bcmath`, `gmp`, `openssl`, `pdo_mysql` (or `pdo_pgsql`), `intl`, `gd`
- MySQL 8 / PostgreSQL 14+, Redis 7, Composer 2
- Node (only if you build a frontend; the admin panel uses the Tailwind CDN)

## Quickstart (local)

```bash
composer install
cp .env.example .env
php artisan key:generate

# 1. Vault encryption key — generate, then store in .env AND offline backup
php artisan vault:key        # prints VAULT_MASTER_KEY=base64:...

# 2. Database (SQLite by default for local dev; MySQL/Redis recommended)
php artisan migrate --seed

# 3. HD seed (encrypted with the vault key) — print the words for offline backup
php artisan vault:generate-seed

# 4. First admin (2FA is mandatory on first login)
php artisan vault:create-admin --email=you@example.com

# run: API + queues + websockets + scheduler
php artisan serve
php artisan horizon      # or: queue:work
php artisan reverb:start
php artisan schedule:work
```

## API (short version)

Base URL `/api/v1` (see `routes/api.php`).

| Method | Endpoint | Notes |
|---|---|---|
| POST | `auth/register` · `auth/login` · `auth/2fa/verify` | Sanctum token flow |
| GET/POST | `2fa/status` `2fa/setup` `2fa/confirm` `2fa/disable` | TOTP lifecycle |
| POST | `deposit/address` | Provisions/returns the per-user address for a chain |
| GET | `balance` | Available + locked per asset |
| GET | `transactions` | Ledger, paginated |
| GET | `withdrawals` | The user's withdrawal history |
| POST | `withdraw/request` | Requires `X-2FA-Code` header; rate limited |
| POST | `webhooks/{provider}` | HMAC-verified deposit notifications |
| GET | `admin/withdrawals/pending` · `admin/stats` | Admin API |
| POST | `admin/withdrawals/approve/{id}` · `reject/{id}` | Admin API, 2FA per action |

## Testing

```bash
php artisan test        # 30 tests: HD vectors, encryption, deposit credit, withdrawal lifecycle
```

Details, testnets and Binance Spot-testnet caveats: **[docs/TESTING.md](docs/TESTING.md)**.

## Deployment

VPS walkthrough (nginx + TLS, Redis, Supervisor, cron, backups, key rotation):
**[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)**. Ready-made configs live in `deploy/`.

## Security warnings (read before going live)

- `VAULT_MASTER_KEY` and the BIP39 mnemonic together control **all** funds.
  Back both up offline, in two places, and never commit them.
- Only ever accept withdrawals to addresses validated per chain
  (`AddressValidator`); Binance keys must have IP allow-listing enabled.
- Keep the EVM hot wallet float small; sweep to cold storage regularly.
- On-chain *sending* is implemented for EVM chains only. BTC/TRON on-chain
  payouts are on the roadmap — today they go through the Binance rail.

## Roadmap

- BTC and TRON on-chain senders (UTXO coin selection, TronGrid signing)
- Automatic hot-wallet sweeping to cold storage
- Multiple Binance accounts / additional exchanges via CCXT rail
- Withdrawal amount anomaly scoring (risk engine)
