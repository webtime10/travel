<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('areas', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->decimal('radius_km', 8, 2)->default(25);
            $table->foreignId('default_base_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->unsignedTinyInteger('min_days')->default(1);
            $table->unsignedTinyInteger('max_days')->default(4);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        Schema::create('area_descriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('area_id')->constrained('areas')->cascadeOnDelete();
            $table->foreignId('language_id')->constrained('languages')->cascadeOnDelete();
            $table->string('name', 255);
            $table->timestamps();

            $table->unique(['area_id', 'language_id']);
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->foreignId('area_id')->nullable()->after('manufacturer_id')->constrained('areas')->nullOnDelete();
            $table->string('trip_role', 16)->nullable()->after('area_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('area_id');
            $table->dropColumn('trip_role');
        });
        Schema::dropIfExists('area_descriptions');
        Schema::dropIfExists('areas');
    }
};
