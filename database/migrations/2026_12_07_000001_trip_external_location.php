<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vị trí của chỗ ở (và nơi đi / đến của vé) để lịch mỗi ngày tính đường từ khách sạn đi và về khách sạn.
 * Khách sạn tìm qua API (payload có lat / lng) được điền sẵn.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tbl_trip_external')) {
            return;
        }
        Schema::table('tbl_trip_external', function (Blueprint $t) {
            if (!Schema::hasColumn('tbl_trip_external', 'latitude')) {
                $t->decimal('latitude', 10, 7)->nullable()->after('note');
                $t->decimal('longitude', 10, 7)->nullable()->after('latitude');
                $t->string('address', 255)->nullable()->after('longitude');
            }
        });
        foreach (DB::table('tbl_trip_external')->where('kind', 'hotel')->whereNull('latitude')->get(['externalId', 'payload']) as $r) {
            $p = json_decode((string) $r->payload, true);
            if (is_array($p) && isset($p['lat'], $p['lng']) && is_numeric($p['lat']) && is_numeric($p['lng'])) {
                DB::table('tbl_trip_external')->where('externalId', $r->externalId)->update(['latitude' => (float) $p['lat'], 'longitude' => (float) $p['lng']]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tbl_trip_external', 'latitude')) {
            Schema::table('tbl_trip_external', fn(Blueprint $t) => $t->dropColumn(['latitude', 'longitude', 'address']));
        }
    }
};
