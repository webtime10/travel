<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ft_short_routes', function (Blueprint $table) {
            $table->json('ru_units')->nullable()->after('meta');
        });
    }

    public function down(): void
    {
        Schema::table('ft_short_routes', function (Blueprint $table) {
            $table->dropColumn('ru_units');
        });
    }
};
