<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FineTunes Short routes — нормализованные дни из New Short (без дат/рекламы/тела Word).
 * New Short / RAG не трогаем: сырой route_text остаётся для поиска.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ft_short_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('new_short_route_id')
                ->constrained('new_short_routes')
                ->cascadeOnDelete();
            $table->string('doc_code', 16)->nullable()->index();
            $table->string('title')->nullable();
            $table->string('title_ru')->nullable();
            $table->unsignedSmallInteger('days_count')->default(0)->index();
            /** Чистый текст: «День 1: …» без 8.4 / дней недели */
            $table->mediumText('route_text')->nullable();
            $table->mediumText('route_text_ru')->nullable();
            /**
             * Структурированные дни:
             * [{day_index, places, places_ru, options?: [...]}]
             */
            $table->json('days')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique('new_short_route_id');
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ft_short_routes');
    }
};
