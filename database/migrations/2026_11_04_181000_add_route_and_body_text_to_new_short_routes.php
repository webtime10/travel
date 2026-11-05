<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Два слоя New Short: маршрут как есть (сверху) + полный текст Word для RAG (снизу).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('new_short_routes', function (Blueprint $table) {
            if (! Schema::hasColumn('new_short_routes', 'route_text')) {
                $table->longText('route_text')->nullable()->after('search_blob');
            }
            if (! Schema::hasColumn('new_short_routes', 'route_text_ru')) {
                $table->longText('route_text_ru')->nullable()->after('route_text');
            }
            if (! Schema::hasColumn('new_short_routes', 'body_text')) {
                $table->longText('body_text')->nullable()->after('route_text_ru');
            }
            if (! Schema::hasColumn('new_short_routes', 'body_text_ru')) {
                $table->longText('body_text_ru')->nullable()->after('body_text');
            }
        });
    }

    public function down(): void
    {
        Schema::table('new_short_routes', function (Blueprint $table) {
            foreach (['route_text', 'route_text_ru', 'body_text', 'body_text_ru'] as $col) {
                if (Schema::hasColumn('new_short_routes', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
