# Deployment guide (Ubuntu VPS)

Target: single Ubuntu 22.04/24.04 VPS running nginx + PHP-FPM + MySQL +
Redis + Supervisor. Scale-out later by moving MySQL/Redis to managed services
and adding app servers (the scheduler uses `onOneServer()`, so multiple app
nodes are safe as long as they share Redis).

---

## 1. Server packages

```bash
sudo apt update && sudo apt -y upgrade
sudo apt -y install nginx mysql-server redis-server supervisor certbot python3-certbot-nginx \
    php8.3-fpm php8.3-cli php8.3-mysql php8.3-redis php8.3-mbstring php8.3-xml \
    php8.3-curl php8.3-zip php8.3-bcmath php8.3-gmp php8.3-intl php8.3-gd unzip git

# Composer
curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer

# Harden MySQL and Redis (bind to localhost, require password)
sudo mysql_secure_installation
```

Verify the extensions the app hard-requires:

```bash
php -m | grep -E 'bcmath|gmp|openssl|pdo_mysql|redis|intl'
```

## 2. Database & Redis

```sql
CREATE DATABASE crypto_vault CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'vault'@'localhost' IDENTIFIED BY 'STRONG_PASSWORD';
GRANT ALL PRIVILEGES ON crypto_vault.* TO 'vault'@'localhost';
FLUSH PRIVILEGES;
```

`/etc/redis/redis.conf`: set `requirepass` (long random), `bind 127.0.0.1 ::1`,
`appendonly yes`. Then `sudo systemctl restart redis-server`.

## 3. Deploy the code

```bash
sudo mkdir -p /var/www/crypto-vault && sudo chown $USER:www-data /var/www/crypto-vault
git clone <your-repo> /var/www/crypto-vault && cd /var/www/crypto-vault

composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

### Production `.env` (essentials)

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://vault.example.com
TRUSTED_PROXIES=127.0.0.1

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=crypto_vault
DB_USERNAME=vault
DB_PASSWORD=...

CACHE_STORE=redis          # REQUIRED: scheduler uses onOneServer() locks
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=...
REDIS_PORT=6379

BROADCAST_CONNECTION=reverb
REVERB_APP_ID=...          # php artisan reverb:start env, generate unique values
REVERB_APP_KEY=...
REVERB_APP_SECRET=...
REVERB_HOST="vault.example.com"
REVERB_PORT=443
REVERB_SCHEME=https

VAULT_MASTER_KEY=base64:...   # see step 4 — back up offline!
BINANCE_TESTNET=false

# enable only the chains you operate
BTC_ENABLED=true
ETH_ENABLED=true
BSC_ENABLED=true
TRON_ENABLED=true
```

## 4. Vault secrets (money-critical — do this carefully)

```bash
# 4.1 Generate the encryption key, add it to .env AND an offline backup NOW.
php artisan vault:key            # prints: VAULT_MASTER_KEY=base64:...
# paste into .env, then:
php artisan config:clear

# 4.2 Encrypted HD seed. PRINT AND STORE THE WORDS OFFLINE (two locations).
php artisan vault:generate-seed  # prints + stores encrypted under vault_secrets

# 4.3 Migrate + seed runtime settings
php artisan migrate --force --seed

# 4.4 First admin (2FA mandatory at first login)
php artisan vault:create-admin --email=you@example.com
```

> Losing `VAULT_MASTER_KEY` **or** the mnemonic means deposits are unrecoverable.
> Never store both in the same place. Never commit them.

Add the Binance API credential (IP-allow-listed, withdrawals enabled) directly
to the `api_keys` table from a maintenance script/tinker; it is stored
encrypted automatically:

```bash
php artisan tinker --execute="App\Models\ApiKey::query()->create([
  'name'=>'main','exchange'=>'binance',
  'key'=>'API_KEY','secret'=>'API_SECRET','is_active'=>true]);"
```

(EVM hot wallet, only if you use the `onchain` rail:
`VaultSecret::put('hot_wallet.evm', '0x….private.key')`.)

## 5. Permissions & caches

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo find /var/www/crypto-vault -type d -exec chmod 755 {} \;
sudo find /var/www/crypto-vault -type f -exec chmod 644 {} \;
php artisan config:cache && php artisan route:cache && php artisan event:cache
```

After **every** deploy:

```bash
php artisan down --secret="deploy-$RANDOM"
git pull && composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart        # picks up new code in workers
php artisan up
```

## 6. nginx + TLS

```bash
sudo cp deploy/nginx.conf /etc/nginx/sites-available/crypto-vault
sudo ln -s /etc/nginx/sites-available/crypto-vault /etc/nginx/sites-enabled/
# edit server_name + paths, add the two limit_req_zone lines to /etc/nginx/nginx.conf http{}
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d vault.example.com
```

The config proxies `wss://vault.example.com/app` → Reverb on `127.0.0.1:8080`
and rate-limits `/api/v1/auth` + `/api/v1/withdraw/request`.

## 7. Supervisor (queues + websockets)

```bash
sudo cp deploy/supervisor-horizon.conf deploy/supervisor-reverb.conf /etc/supervisor/conf.d/
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl status
# crypto-vault-horizon  RUNNING
# crypto-vault-reverb   RUNNING
```

Use **either** Horizon **or** `deploy/supervisor-queue.conf` (plain workers) —
not both.

## 8. Scheduler (cron)

```bash
sudo crontab -u www-data -e
# * * * * * cd /var/www/crypto-vault && php artisan schedule:run >> /dev/null 2>&1
```

This drives: per-chain deposit scanners (every minute), Binance treasury sync,
withdrawal status polling, `horizon:snapshot`, `vault:prune-logs`.
Overlap between nodes is prevented by `onOneServer()` — **Redis must be the
cache store** for this to be correct.

## 9. Backups

```bash
sudo install -m 750 deploy/backup.sh /usr/local/bin/vault-backup
sudo crontab -e
# 15 3 * * * /usr/local/bin/vault-backup >> /var/log/vault-backup.log 2>&1
```

Backs up the database + `.env` (yes, the vault key lives there — keep the
backup directory root-only, or push it to encrypted off-site storage).
Restore drill (test it before you need it!):

```bash
gunzip -c /var/backups/crypto-vault/db-YYYYMMDD.sql.gz | mysql -u vault -p crypto_vault
tar -xzf /var/backups/crypto-vault/app-YYYYMMDD.tar.gz -C /var/www/crypto-vault .env
```

**Offline, independent copies required:** `VAULT_MASTER_KEY` + BIP39 mnemonic.
Without both, no backup can restore user funds.

## 10. Key rotation (`VAULT_MASTER_KEY`)

```bash
# 1. Generate the new key; keep the old one for the rotation command
php artisan vault:key                    # -> NEW_KEY
# 2. In .env: VAULT_MASTER_KEY=NEW_KEY ; php artisan config:clear
# 3. Re-encrypt everything (vault_secrets, api_keys, 2FA secrets)
php artisan vault:reencrypt --old-key="base64:OLD_KEY"
# 4. Smoke test: login (2FA works), one test deposit address derivation
php artisan tinker --execute="echo app(App\Services\Blockchain\HdWalletService::class)->derive(App\Enums\Chain::Eth, 0)->address;"
# 5. Re-run backups; destroy the old key copy once verified
```

## 11. Monitoring & operations

- `tail -f storage/logs/laravel.log` — application log
- `/horizon` (admin session) — queue throughput, failed jobs (auto-retried)
- Admin panel `/admin` — pending approvals, deposits, audit log
- `php artisan vault:scan-deposits --chain=eth` — manual scan (debug)
- `php artisan vault:prune-logs` — retention (scheduled daily)
- Alerting: monitor Horizon “failed jobs”, `CheckWithdrawalStatusJob` errors and
  Binance 4xx spikes (see `Log::warning` entries).

## 12. Framework upgrade (Laravel 11 → 12)

Laravel 11 left security support in **March 2026**. This codebase avoids
11-only APIs, so the upgrade is intentionally small:

```bash
composer require "laravel/framework:^12.0" --with-all-dependencies
composer require --dev "nunomaduro/collision:^8.0"   # keep if compatible
php artisan config:clear && php artisan test           # 30 tests must pass
```

Check the official upgrade guide for anything else that ships in your lockfile
(Horizon/Sanctum/Reverb releases are compatible with 12.x at the time of
writing).

## 13. Go-live checklist

- [ ] `APP_DEBUG=false`, `APP_ENV=production`, HTTPS enforced (HSTS header set)
- [ ] `VAULT_MASTER_KEY` + mnemonic printed and stored in **two offline locations**
- [ ] Redis password set; `CACHE_STORE=redis` (scheduler correctness)
- [ ] Chain scanners enabled only for chains you intend to operate
- [ ] Binance key: withdrawals enabled, IP allow-list = this server only
- [ ] Testnet dry-run done first (`docs/TESTING.md`), then a **small-value real**
      deposit → credit → withdrawal cycle end-to-end
- [ ] Supervisor shows horizon + reverb RUNNING; cron installed for www-data
- [ ] Backups running (verify a restore into a scratch database)
- [ ] Admin users all have confirmed 2FA; recovery codes stored offline
- [ ] Monitoring for failed jobs configured
