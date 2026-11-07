<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ft_short_routes', function (Blueprint $table) {
            $table->json('reverse_principle_claude')->nullable()->after('reverse_principle_ru');
            $table->json('reverse_principle_claude_ru')->nullable()->after('reverse_principle_claude');
        });
    }

    public function down(): void
    {
        Schema::table('ft_short_routes', function (Blueprint $table) {
            $table->dropColumn(['reverse_principle_claude', 'reverse_principle_claude_ru']);
        });
    }
};
