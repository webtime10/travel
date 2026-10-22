<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('route_skeletons', function (Blueprint $table) {
            $table->string('title_ru')->nullable()->after('title');
            $table->string('arrival_hub_ru', 120)->nullable()->after('arrival_hub');
            $table->string('departure_hub_ru', 120)->nullable()->after('departure_hub');
            /** units key => ru text (прогресс перевода) */
            $table->json('ru_units')->nullable()->after('meta');
        });

        Schema::table('route_skeleton_days', function (Blueprint $table) {
            $table->string('title_ru')->nullable()->after('title');
            $table->string('start_point_ru', 255)->nullable()->after('start_point');
            $table->string('end_point_ru', 255)->nullable()->after('end_point');
            /** list<{id?:int,name:string}> на русском */
            $table->json('places_ru')->nullable()->after('places');
        });
    }

    public function down(): void
    {
        Schema::table('route_skeletons', function (Blueprint $table) {
            $table->dropColumn(['title_ru', 'arrival_hub_ru', 'departure_hub_ru', 'ru_units']);
        });
        Schema::table('route_skeleton_days', function (Blueprint $table) {
            $table->dropColumn(['title_ru', 'start_point_ru', 'end_point_ru', 'places_ru']);
        });
    }
};
