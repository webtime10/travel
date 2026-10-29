<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_routes', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('country_code', 8)->default('UA')->index();
            $table->unsignedSmallInteger('days_count')->default(0);
            $table->text('query_text')->nullable();
            /** Assembled slots: [{day, hub, golden_day_id, title, labels, stops}] */
            $table->json('payload')->nullable();
            $table->json('prefs')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_routes');
    }
};
