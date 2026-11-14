<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lên kế hoạch nhóm và chia sẻ:
 * - tbl_trips.joinToken / joinRole: liên kết MỜI CÙNG LÊN KẾ HOẠCH (đăng nhập rồi bấm là vào nhóm với quyền joinRole).
 * - tbl_trips.shareNotes / shareBookings / shareCosts: bản chia sẻ chỉ xem có hiện ghi chú, đặt chỗ, chi phí hay không.
 * - tbl_trip_guests: người đi cùng KHÔNG có tài khoản (để chia chi phí).
 * - tbl_trip_expenses + tbl_trip_expense_shares: khoản chi của nhóm; "who" = 'u:{userId}' hoặc 'g:{guestId}'.
 * Đặt chỗ ngoài (khách sạn, vé máy bay, tàu, xe):
 * - tbl_trip_external.status (planned|booked|cancelled), needBy (hạn nên đặt), endAt (trả phòng / đến nơi), bookUrl, note.
 * Chế độ đang đi:
 * - tbl_trip_items.skippedAt: bỏ qua mục này trong ngày (khác "đã ghé" visitedAt).
 * - tbl_trips.bookingSkip: các nhắc đặt chỗ người dùng đã chọn "không cần" (JSON).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbl_trips')) {
            Schema::table('tbl_trips', function (Blueprint $t) {
                if (!Schema::hasColumn('tbl_trips', 'joinToken')) {
                    $t->char('joinToken', 32)->nullable()->unique('uq_trips_join_token');
                    $t->string('joinRole', 4)->default('edit');
                }
                if (!Schema::hasColumn('tbl_trips', 'shareNotes')) {
                    $t->boolean('shareNotes')->default(true);
                    $t->boolean('shareBookings')->default(false);
                    $t->boolean('shareCosts')->default(false);
                }
                if (!Schema::hasColumn('tbl_trips', 'bookingSkip')) {
                    $t->text('bookingSkip')->nullable();
                }
            });
        }
        if (!Schema::hasTable('tbl_trip_guests')) {
            Schema::create('tbl_trip_guests', function (Blueprint $t) {
                $t->increments('guestId');
                $t->integer('tripId')->index();
                $t->string('name', 60);
                $t->timestamp('createdAt')->nullable();
            });
        }
        if (!Schema::hasTable('tbl_trip_expenses')) {
            Schema::create('tbl_trip_expenses', function (Blueprint $t) {
                $t->increments('expenseId');
                $t->integer('tripId')->index();
                $t->string('title', 120);
                $t->string('category', 16)->default('other');   // food | stay | move | ticket | shop | other
                $t->unsignedBigInteger('amount');                   // VND, số nguyên
                $t->string('paidBy', 16);                           // u:12 | g:3
                $t->string('splitMode', 8)->default('equal');       // equal | custom
                $t->date('spentOn')->nullable();
                $t->string('note', 255)->nullable();
                $t->integer('createdBy')->nullable();
                $t->timestamp('createdAt')->nullable();
                $t->timestamp('updatedAt')->nullable();
            });
        }
        if (!Schema::hasTable('tbl_trip_expense_shares')) {
            Schema::create('tbl_trip_expense_shares', function (Blueprint $t) {
                $t->increments('shareId');
                $t->unsignedInteger('expenseId')->index();
                $t->string('who', 16);
                $t->unsignedBigInteger('amount');
                $t->unique(['expenseId', 'who'], 'uq_expense_share_who');
            });
        }
        if (Schema::hasTable('tbl_trip_external') && !Schema::hasColumn('tbl_trip_external', 'status')) {
            Schema::table('tbl_trip_external', function (Blueprint $t) {
                $t->string('status', 10)->default('booked');   // planned | booked | cancelled
                $t->date('needBy')->nullable();
                $t->dateTime('endAt')->nullable();
                $t->string('bookUrl', 500)->nullable();
                $t->string('note', 255)->nullable();
            });
        }
        // Bản trước ghi "· mã XXX" vào ghi chú của mục lịch tạo từ xác nhận đặt chỗ -> lộ ở bản chia sẻ / Cộng đồng. Gỡ mã khỏi ghi chú.
        if (Schema::hasTable('tbl_trip_items')) {
            \Illuminate\Support\Facades\DB::table('tbl_trip_items')->where('note', 'like', 'Đã đặt ngoài Travela · mã %')->update(['note' => 'Đã đặt ngoài Travela']);
        }
        if (Schema::hasTable('tbl_trip_items') && !Schema::hasColumn('tbl_trip_items', 'skippedAt')) {
            Schema::table('tbl_trip_items', fn(Blueprint $t) => $t->timestamp('skippedAt')->nullable());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_trip_expense_shares');
        Schema::dropIfExists('tbl_trip_expenses');
        Schema::dropIfExists('tbl_trip_guests');
        if (Schema::hasColumn('tbl_trips', 'joinToken')) {
            Schema::table('tbl_trips', function (Blueprint $t) {
                $t->dropUnique('uq_trips_join_token');
                $t->dropColumn(['joinToken', 'joinRole', 'shareNotes', 'shareBookings', 'shareCosts', 'bookingSkip']);
            });
        }
        if (Schema::hasColumn('tbl_trip_external', 'status')) {
            Schema::table('tbl_trip_external', fn(Blueprint $t) => $t->dropColumn(['status', 'needBy', 'endAt', 'bookUrl', 'note']));
        }
        if (Schema::hasColumn('tbl_trip_items', 'skippedAt')) {
            Schema::table('tbl_trip_items', fn(Blueprint $t) => $t->dropColumn('skippedAt'));
        }
    }
};
