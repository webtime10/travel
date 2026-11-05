<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * New Short routes — каркасы из папки doc/*.docx (боевые Word), параллельно старым short routes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('new_short_routes', function (Blueprint $table) {
            $table->id();
            /** 4 цифры из имени файла (первая группа; годы 20xx пропускаем) */
            $table->string('doc_code', 16)->nullable()->index();
            $table->string('source_filename', 512)->nullable();
            $table->string('title')->nullable();
            $table->string('title_ru')->nullable();
            $table->date('start_date')->nullable();
            $table->unsignedSmallInteger('days_count')->default(0)->index();
            $table->string('arrival_hub', 255)->nullable()->index();
            $table->string('arrival_hub_ru', 255)->nullable();
            $table->string('departure_hub', 255)->nullable();
            $table->string('departure_hub_ru', 255)->nullable();
            $table->unsignedBigInteger('arrival_location_id')->nullable()->index();
            $table->unsignedBigInteger('departure_location_id')->nullable()->index();
            $table->text('search_blob')->nullable();
            $table->json('meta')->nullable();
            $table->json('ru_units')->nullable();
            $table->timestamps();

            $table->unique(['doc_code', 'source_filename']);
        });

        Schema::create('new_short_route_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('new_short_route_id')->constrained('new_short_routes')->cascadeOnDelete();
            $table->unsignedSmallInteger('day_index')->default(0);
            $table->string('title')->nullable();
            $table->string('title_ru')->nullable();
            $table->string('start_point', 255)->nullable();
            $table->string('start_point_ru', 255)->nullable();
            $table->string('end_point', 255)->nullable();
            $table->string('end_point_ru', 255)->nullable();
            /** Текст ночёвки из Word (לינה / overnight) */
            $table->string('overnight_text', 512)->nullable();
            $table->string('overnight_text_ru', 512)->nullable();
            $table->unsignedBigInteger('overnight_location_id')->nullable()->index();
            $table->json('places')->nullable();
            $table->json('places_ru')->nullable();
            $table->timestamps();

            $table->index(['new_short_route_id', 'day_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('new_short_route_days');
        Schema::dropIfExists('new_short_routes');
    }
};
