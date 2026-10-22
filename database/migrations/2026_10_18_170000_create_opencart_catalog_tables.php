<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manufacturers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 8)->nullable()->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        Schema::create('manufacturer_descriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manufacturer_id')->constrained('manufacturers')->cascadeOnDelete();
            $table->foreignId('language_id')->constrained('languages')->cascadeOnDelete();
            $table->string('name', 255);
            $table->timestamps();
            $table->unique(['manufacturer_id', 'language_id']);
        });

        Schema::create('attribute_groups', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('attribute_group_descriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attribute_group_id')->constrained('attribute_groups')->cascadeOnDelete();
            $table->foreignId('language_id')->constrained('languages')->cascadeOnDelete();
            $table->string('name', 64);
            $table->timestamps();
            $table->unique(['attribute_group_id', 'language_id'], 'attr_group_desc_unique');
        });

        Schema::create('attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attribute_group_id')->constrained('attribute_groups')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('attribute_descriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attribute_id')->constrained('attributes')->cascadeOnDelete();
            $table->foreignId('language_id')->constrained('languages')->cascadeOnDelete();
            $table->string('name', 64);
            $table->timestamps();
            $table->unique(['attribute_id', 'language_id']);
        });

        Schema::create('location_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained('attributes')->cascadeOnDelete();
            $table->foreignId('language_id')->constrained('languages')->cascadeOnDelete();
            $table->text('text')->nullable();
            $table->timestamps();
            $table->unique(['location_id', 'attribute_id', 'language_id'], 'location_attribute_unique');
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->foreignId('manufacturer_id')->nullable()->after('model')->constrained('manufacturers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manufacturer_id');
        });
        Schema::dropIfExists('location_attributes');
        Schema::dropIfExists('attribute_descriptions');
        Schema::dropIfExists('attributes');
        Schema::dropIfExists('attribute_group_descriptions');
        Schema::dropIfExists('attribute_groups');
        Schema::dropIfExists('manufacturer_descriptions');
        Schema::dropIfExists('manufacturers');
    }
};
