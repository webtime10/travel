<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Краткие каркасы маршрутов директора (из travel-python Route / RouteDay).
 * Используются как образцы при сборке и как источник для fine-tune.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_skeletons', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('source_route_id')->nullable()->index();
            $table->string('title')->nullable();
            $table->date('start_date')->nullable();
            $table->unsignedSmallInteger('days_count')->default(0)->index();
            $table->boolean('is_template')->default(false)->index();
            $table->string('arrival_hub', 120)->nullable()->index();
            $table->string('departure_hub', 120)->nullable();
            /** Поисковый текст для похожих: title + hubs + places */
            $table->text('search_blob')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['source_route_id']);
        });

        Schema::create('route_skeleton_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_skeleton_id')->constrained('route_skeletons')->cascadeOnDelete();
            $table->unsignedSmallInteger('day_index')->default(0);
            $table->string('title')->nullable();
            $table->string('start_point', 255)->nullable();
            $table->string('end_point', 255)->nullable();
            /** list<{id?:int,name:string}> */
            $table->json('places')->nullable();
            $table->timestamps();

            $table->index(['route_skeleton_id', 'day_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_skeleton_days');
        Schema::dropIfExists('route_skeletons');
    }
};
