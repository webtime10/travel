<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('place_coordinates', function (Blueprint $table) {
            $table->id();
            $table->string('place_key', 191)->unique();
            $table->string('query', 255);
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('display_name', 500)->nullable();
            $table->string('source', 32)->default('nominatim');
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('place_coordinates');
    }
};
