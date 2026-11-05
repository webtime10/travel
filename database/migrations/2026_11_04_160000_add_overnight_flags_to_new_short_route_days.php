<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('new_short_route_days', function (Blueprint $table) {
            if (! Schema::hasColumn('new_short_route_days', 'is_overnight')) {
                $table->boolean('is_overnight')->default(false)->after('overnight_text');
            }
            if (! Schema::hasColumn('new_short_route_days', 'overnight_note')) {
                $table->text('overnight_note')->nullable()->after('is_overnight');
            }
        });

        // Clear false auto-links from probe
        if (Schema::hasColumn('new_short_route_days', 'overnight_location_id')) {
            \Illuminate\Support\Facades\DB::table('new_short_route_days')->update([
                'overnight_location_id' => null,
            ]);
        }
        if (Schema::hasColumn('new_short_routes', 'arrival_location_id')) {
            \Illuminate\Support\Facades\DB::table('new_short_routes')->update([
                'arrival_location_id' => null,
                'departure_location_id' => null,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('new_short_route_days', function (Blueprint $table) {
            if (Schema::hasColumn('new_short_route_days', 'overnight_note')) {
                $table->dropColumn('overnight_note');
            }
            if (Schema::hasColumn('new_short_route_days', 'is_overnight')) {
                $table->dropColumn('is_overnight');
            }
        });
    }
};
