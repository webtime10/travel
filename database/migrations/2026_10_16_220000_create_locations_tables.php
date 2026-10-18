<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('model', 64)->nullable()->unique();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('status')->default(true);
            $table->json('meta')->nullable();
            $table->json('meta_ru')->nullable();
            $table->timestamps();
        });

        Schema::create('location_descriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->foreignId('language_id')->constrained('languages')->cascadeOnDelete();
            $table->string('name', 255);
            $table->mediumText('description')->nullable();
            $table->timestamps();

            $table->unique(['location_id', 'language_id']);
        });

        Schema::create('category_location', function (Blueprint $table) {
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->primary(['location_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_location');
        Schema::dropIfExists('location_descriptions');
        Schema::dropIfExists('locations');
    }
};
