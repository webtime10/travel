<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('golden_days', function (Blueprint $table) {
            if (! Schema::hasColumn('golden_days', 'reverse_tags')) {
                $table->json('reverse_tags')->nullable()->after('meta');
            }
        });

        Schema::table('route_skeletons', function (Blueprint $table) {
            if (! Schema::hasColumn('route_skeletons', 'reverse_principle')) {
                $table->json('reverse_principle')->nullable()->after('meta');
            }
        });
    }

    public function down(): void
    {
        Schema::table('golden_days', function (Blueprint $table) {
            if (Schema::hasColumn('golden_days', 'reverse_tags')) {
                $table->dropColumn('reverse_tags');
            }
        });

        Schema::table('route_skeletons', function (Blueprint $table) {
            if (Schema::hasColumn('route_skeletons', 'reverse_principle')) {
                $table->dropColumn('reverse_principle');
            }
        });
    }
};
