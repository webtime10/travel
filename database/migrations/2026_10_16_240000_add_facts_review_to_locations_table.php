<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->string('facts_status', 16)->nullable()->index()->after('meta_ru');
            $table->unsignedTinyInteger('facts_coverage')->nullable()->after('facts_status');
            $table->json('facts_review')->nullable()->after('facts_coverage');
            $table->timestamp('facts_split_at')->nullable()->after('facts_review');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropIndex(['facts_status']);
            $table->dropColumn(['facts_status', 'facts_coverage', 'facts_review', 'facts_split_at']);
        });
    }
};
