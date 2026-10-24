<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('golden_days', function (Blueprint $table) {
            if (! Schema::hasColumn('golden_days', 'start_point_ru')) {
                $table->string('start_point_ru', 255)->nullable()->after('start_point');
            }
            if (! Schema::hasColumn('golden_days', 'end_point_ru')) {
                $table->string('end_point_ru', 255)->nullable()->after('end_point');
            }
            if (! Schema::hasColumn('golden_days', 'ru_units')) {
                $table->json('ru_units')->nullable()->after('meta');
            }
        });

        Schema::table('golden_day_stops', function (Blueprint $table) {
            if (! Schema::hasColumn('golden_day_stops', 'name_ru')) {
                $table->string('name_ru')->nullable()->after('name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('golden_days', function (Blueprint $table) {
            foreach (['start_point_ru', 'end_point_ru', 'ru_units'] as $col) {
                if (Schema::hasColumn('golden_days', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        Schema::table('golden_day_stops', function (Blueprint $table) {
            if (Schema::hasColumn('golden_day_stops', 'name_ru')) {
                $table->dropColumn('name_ru');
            }
        });
    }
};
