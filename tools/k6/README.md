# Đo tải (J1) và tranh chỗ (J2)

## Chạy k6

```bash
# máy chủ: LOADTEST_NO_THROTTLE=true, QUERY_COUNT_HEADER=true (không có tác dụng ở production)
php artisan db:seed --class=CommunityDemoSeeder          # có chuyến công khai để đo trang Cộng đồng
php artisan cache:clear && (khởi động lại php-fpm)        # lần "cold"
k6 run -e BASE=http://localhost:8080 -e PHASE=cold -e VUS=50 -e DURATION=60s -e OUT_MD=tools/k6/results/cold.md tools/k6/travela.js
k6 run -e BASE=http://localhost:8080 -e PHASE=warm -e VUS=50 -e DURATION=60s -e OUT_MD=tools/k6/results/warm.md tools/k6/travela.js
```

Kịch bản: 70 % người xem (danh sách tour → chi tiết tour + API số chỗ → Cộng đồng → một chuyến công khai),
20 % người đặt tour (API số chỗ → báo giá 3 lần), 10 % người đã đăng nhập mở "Chuyến đi của tôi". Mỗi người nghỉ 1–3 giây giữa các bước.
Ngưỡng: lỗi < 1 %, p95 < 300 ms (N-03); lần warm thêm TTFB p95 < 200 ms (N-03a).

## Kết quả đo ngày 02/11/2026

Môi trường: một máy ảo 2 vCPU chạy cùng lúc nginx 1.24 + php-fpm 8.3 (16 tiến trình, OPcache mặc định) + MariaDB + k6;
50 người dùng ảo, 60 giây mỗi lần. Số tuyệt đối sẽ tốt hơn khi k6 chạy trên máy khác.

| Lần đo | p95 toàn bộ | TTFB p95 | p95 danh sách tour | request/giây | lỗi | truy vấn SQL / request (tb · max) |
|---|---:|---:|---:|---:|---:|---|
| Trước khi sửa cấu hình nginx (keepalive tới php-fpm) | 130 ms nhưng **34 request treo 60 giây** | — | 60 000 ms | 14,7 | 3,35 % | 7,0 · 20 |
| Cache file, cold | 243 ms | 243 ms | 627 ms | 37,6 | 0 % | 7,1 · 20 |
| Cache file, warm | **106 ms** | **106 ms** | 438 ms | 39,2 | 0 % | 7,2 · 20 |
| Redis (cache + phiên, predis), warm | 177 ms | 176 ms | 635 ms | 38,6 | 0 % | 7,2 · 20 |

Chi tiết từng trang: `results/file-cache-cold.md`, `results/file-cache-warm.md`, `results/redis-warm.md`.

Nhận xét:
- Đạt N-03 (p95 < 300 ms) và N-03a (TTFB p95 < 200 ms) ở lần warm. Riêng trang danh sách tour vượt 300 ms ở p95 khi có tải
  (gọi một mình chỉ ~30 ms, 10 truy vấn) — máy 2 vCPU bị k6 chiếm một phần CPU; nên đo lại trên máy riêng trước khi kết luận.
- Lần đầu đo phát hiện lỗi cấu hình: `fastcgi_keep_conn on` + `keepalive` khiến kết nối nhàn rỗi của nginx giữ chết tiến trình php-fpm,
  request mới nằm chờ tới hết 60 giây. Đã bỏ trong `docker/nginx/default.conf` (có ghi chú).
- Redis trên cùng máy với client `predis` (PHP thuần) chậm hơn cache file ở mức tải này; lợi ích chính của Redis là dùng chung giữa nhiều
  container (session, khóa giữ chỗ, hàng đợi), không phải tốc độ trên một máy. Dùng extension `phpredis` sẽ giảm chi phí này.

## Tranh chỗ (J2, N-01)

```bash
php artisan bookings:race-test {tourId} --users=100 --slots=5
CACHE_STORE=redis php artisan bookings:race-test {tourId} --users=100 --slots=5   # có thêm Cache::lock avail:{id}
```

Kết quả (02/11/2026, cả hai cấu hình): **5/100 thành công, còn 0 chỗ, PASS — không bán lố**; 100 tiến trình xong trong ~12 giây.
Lệnh tự đặt số chỗ trên ngày khởi hành, chạy, rồi khôi phục và xóa đơn thử.
IPN VNPay gửi lặp (N-02): `tests/Feature/VnpayPaymentTest::test_ipn_success_confirms_once_even_if_sent_ten_times`.
