#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Crypto Vault — nightly backup (database + app secrets + storage)
#
# Install (run as root):
#   install -m 750 deploy/backup.sh /usr/local/bin/vault-backup
#   crontab -e   ->  15 3 * * * /usr/local/bin/vault-backup >> /var/log/vault-backup.log 2>&1
#
# Covers:
#   - MySQL dump (falls back to PostgreSQL if DB_CONNECTION=pgsql)
#   - .env (contains VAULT_MASTER_KEY!!)  <- encrypt or store offline
#   - storage/app (cold wallet files, if any) + public/uploads
#
# IMPORTANT: losing VAULT_MASTER_KEY makes every encrypted value
# (HD seed, hot wallet key, exchange API keys, 2FA secrets) unrecoverable.
# Keep one copy in a password manager and one offline.
# ---------------------------------------------------------------------------
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/crypto-vault}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/crypto-vault}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
STAMP="$(date +%Y%m%d-%H%M%S)"
KEEP_FILES=20

mkdir -p "$BACKUP_DIR"
cd "$APP_DIR"

# shellcheck disable=SC1091
set -a; source .env; set +a

# ---------------------------------------------------------------- database --
if [[ "${DB_CONNECTION:-mysql}" == "pgsql" ]]; then
    PGPASSWORD="${DB_PASSWORD:-}" pg_dump \
        -h "${DB_HOST:-127.0.0.1}" -p "${DB_PORT:-5432}" \
        -U "${DB_USERNAME:-root}" "${DB_DATABASE:-crypto_vault}" \
        | gzip > "$BACKUP_DIR/db-$STAMP.sql.gz"
else
    mysqldump \
        --single-transaction --quick --routines --triggers \
        -h "${DB_HOST:-127.0.0.1}" -P "${DB_PORT:-3306}" \
        -u "${DB_USERNAME:-root}" ${DB_PASSWORD:+-p"$DB_PASSWORD"} \
        "${DB_DATABASE:-crypto_vault}" \
        | gzip > "$BACKUP_DIR/db-$STAMP.sql.gz"
fi

# ------------------------------------------------------- app secrets + data --
tar -czf "$BACKUP_DIR/app-$STAMP.tar.gz" \
    .env \
    storage/app \
    $( [ -d public/uploads ] && echo public/uploads )

# Archive .env inside an extra 0600 tarball to avoid world-readable copies.
chmod 600 "$BACKUP_DIR"/*.gz

# ------------------------------------------------------------- retention ----
find "$BACKUP_DIR" -name '*.gz' -mtime "+$RETENTION_DAYS" -delete
ls -1t "$BACKUP_DIR"/db-*.sql.gz  2>/dev/null | tail -n +$((KEEP_FILES + 1)) | xargs -r rm -f
ls -1t "$BACKUP_DIR"/app-*.tar.gz 2>/dev/null | tail -n +$((KEEP_FILES + 1)) | xargs -r rm -f

echo "[$(date -Is)] backup ok: $BACKUP_DIR/db-$STAMP.sql.gz"
