<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            if (! Schema::hasColumn('locations', 'weight')) {
                $table->unsignedTinyInteger('weight')->default(5)->after('sort_order')->index();
            }
            if (! Schema::hasColumn('locations', 'visit_hours')) {
                $table->decimal('visit_hours', 4, 1)->nullable()->after('weight');
            }
            if (! Schema::hasColumn('locations', 'kosher_food')) {
                // yes | no | unknown
                $table->string('kosher_food', 16)->default('unknown')->after('visit_hours')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            if (Schema::hasColumn('locations', 'kosher_food')) {
                $table->dropColumn('kosher_food');
            }
            if (Schema::hasColumn('locations', 'visit_hours')) {
                $table->dropColumn('visit_hours');
            }
            if (Schema::hasColumn('locations', 'weight')) {
                $table->dropColumn('weight');
            }
        });
    }
};
