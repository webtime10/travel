<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('model', 64)->nullable()->unique()->after('id');
            $table->json('meta_ru')->nullable()->after('meta');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['model']);
            $table->dropColumn(['model', 'meta_ru']);
        });
    }
};
