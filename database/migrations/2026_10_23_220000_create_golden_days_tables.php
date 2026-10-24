<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Золотые дни (блочное планирование): готовые связки локаций из каркасов маршрутов.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('golden_days', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->string('title_ru')->nullable();
            $table->foreignId('area_id')->nullable()->constrained('areas')->nullOnDelete();
            $table->unsignedBigInteger('source_skeleton_id')->nullable()->index();
            $table->unsignedBigInteger('source_skeleton_day_id')->nullable()->index();
            $table->unsignedSmallInteger('source_day_index')->nullable();
            $table->string('start_point', 255)->nullable();
            $table->string('end_point', 255)->nullable();
            /** sha1 of ordered stop keys (model|name) — dedupe identical days */
            $table->string('signature', 64)->index();
            $table->unsignedSmallInteger('stops_count')->default(0);
            $table->unsignedInteger('usage_count')->default(1);
            $table->boolean('is_active')->default(true)->index();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique('signature');
        });

        Schema::create('golden_day_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('golden_day_id')->constrained('golden_days')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            /** Python location id as string (= locations.model) */
            $table->string('source_model', 64)->nullable()->index();
            $table->string('name')->nullable();
            $table->timestamps();

            $table->index(['golden_day_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('golden_day_stops');
        Schema::dropIfExists('golden_days');
    }
};
