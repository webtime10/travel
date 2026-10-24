<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Справочник меток для golden days (многоязычные названия) + pivot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('golden_labels', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->nullable()->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('status')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('golden_label_descriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('golden_label_id')->constrained('golden_labels')->cascadeOnDelete();
            $table->foreignId('language_id')->constrained('languages')->cascadeOnDelete();
            $table->string('name', 128);
            $table->timestamps();

            $table->unique(['golden_label_id', 'language_id']);
        });

        Schema::create('golden_day_label', function (Blueprint $table) {
            $table->id();
            $table->foreignId('golden_day_id')->constrained('golden_days')->cascadeOnDelete();
            $table->foreignId('golden_label_id')->constrained('golden_labels')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['golden_day_id', 'golden_label_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('golden_day_label');
        Schema::dropIfExists('golden_label_descriptions');
        Schema::dropIfExists('golden_labels');
    }
};
