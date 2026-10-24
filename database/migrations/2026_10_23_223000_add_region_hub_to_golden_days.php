<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('golden_days', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('area_id')->constrained('categories')->nullOnDelete();
            $table->string('hub_key', 64)->nullable()->after('category_id')->index();
            $table->text('search_blob')->nullable()->after('hub_key');
            $table->string('junk_reason', 120)->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('golden_days', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
            $table->dropColumn(['hub_key', 'search_blob', 'junk_reason']);
        });
    }
};
