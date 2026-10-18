<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_facts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->string('group', 32)->index();
            $table->string('address', 500)->nullable();
            $table->text('map_url')->nullable();
            $table->text('waze_url')->nullable();
            $table->text('website')->nullable();
            $table->boolean('is_kosher')->default(false);
            $table->unsignedBigInteger('source_file_id')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('location_fact_descriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_fact_id')->constrained('location_facts')->cascadeOnDelete();
            $table->foreignId('language_id')->constrained('languages')->cascadeOnDelete();
            $table->string('type', 255)->nullable();
            $table->string('name', 255);
            $table->text('text')->nullable();
            $table->text('hours')->nullable();
            $table->timestamps();

            $table->unique(['location_fact_id', 'language_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_fact_descriptions');
        Schema::dropIfExists('location_facts');
    }
};
