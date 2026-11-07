<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reverse engineering на New Short: полный Word + короткий каркас → JSON.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('new_short_routes', function (Blueprint $table) {
            $table->json('reverse_principle')->nullable()->after('ru_units');
            $table->json('reverse_principle_ru')->nullable()->after('reverse_principle');
        });
    }

    public function down(): void
    {
        Schema::table('new_short_routes', function (Blueprint $table) {
            $table->dropColumn(['reverse_principle', 'reverse_principle_ru']);
        });
    }
};
