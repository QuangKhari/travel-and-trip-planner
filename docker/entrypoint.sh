#!/bin/sh
# Khởi động container Travela (B2, PF-02).
#   ROLE=app        php-fpm (mặc định): chờ MySQL, chạy migrate (MIGRATE=1), dựng cache cấu hình / route / view / event
#   ROLE=worker     queue worker (email, hóa đơn...)
#   ROLE=scheduler  schedule:work (giữ chỗ hết hạn mỗi phút, nhắc lịch, đồng bộ lịch khởi hành...)
set -e
cd /var/www/html

wait_db() {
    i=0
    until php -r 'try { new PDO("mysql:host=".getenv("DB_HOST").";port=".(getenv("DB_PORT")?:3306), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); exit(0);} catch (Throwable $e) { exit(1);} '; do
        i=$((i+1)); [ "$i" -gt 60 ] && echo "MySQL chưa sẵn sàng sau 120 giây" && exit 1
        sleep 2
    done
}

if [ -z "$APP_KEY" ]; then echo "Thiếu APP_KEY (php artisan key:generate --show)"; exit 1; fi
wait_db

if [ "${ROLE:-app}" = "app" ]; then
    php artisan storage:link >/dev/null 2>&1 || true
    [ "${MIGRATE:-1}" = "1" ] && php artisan migrate --force
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan event:cache
else
    # worker / scheduler dùng cùng bộ cache cấu hình; chờ container app dựng xong
    php artisan config:cache >/dev/null
fi

case "${ROLE:-app}" in
    worker)    exec php artisan queue:work --sleep=1 --tries=3 --backoff=30 --max-time=3600 --memory=256 ;;
    scheduler) exec php artisan schedule:work ;;
    *)         exec "$@" ;;
esac
