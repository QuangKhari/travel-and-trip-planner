composer install
copy .env.example .env (rồi điền DB và mail)
php artisan key:generate
php artisan migrate
php artisan db:seed
php artisan storage:link
php artisan serve
php artisan schedule:work (cửa sổ khác: hủy đơn quá hạn giữ chỗ)
