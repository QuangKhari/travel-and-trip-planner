#!/bin/sh
# Sao lưu CSDL hằng ngày (Đợt D mục D7): nén gzip, giữ BACKUP_KEEP_DAYS ngày gần nhất (mặc định 14).
#   docker compose --profile backup up -d backup
# Khôi phục:  gunzip -c /backups/travela-YYYYmmdd-HHMM.sql.gz | docker compose exec -T mysql mysql -u root -p"$DB_ROOT_PASSWORD" travela
set -e
KEEP="${BACKUP_KEEP_DAYS:-14}"
while true; do
    f="/backups/travela-$(date +%Y%m%d-%H%M).sql.gz"
    if mysqldump -h mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" --single-transaction --quick --routines --no-tablespaces "$DB_DATABASE" | gzip > "$f.tmp"; then
        mv "$f.tmp" "$f"
        echo "$(date -Iseconds) đã sao lưu $f ($(du -h "$f" | cut -f1))"
    else
        rm -f "$f.tmp"
        echo "$(date -Iseconds) LỖI sao lưu" >&2
    fi
    find /backups -name 'travela-*.sql.gz' -mtime +"$KEEP" -delete
    sleep "${BACKUP_EVERY_SECONDS:-86400}"
done
