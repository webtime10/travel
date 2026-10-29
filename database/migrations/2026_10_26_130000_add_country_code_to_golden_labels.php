<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('golden_labels', function (Blueprint $table) {
            if (! Schema::hasColumn('golden_labels', 'country_code')) {
                // IT | UA | BOTH
                $table->string('country_code', 8)->default('BOTH')->after('slug')->index();
            }
        });

        // Heuristic backfill
        $uaHubs = [
            'hub-lviv', 'hub-carpathians', 'hub-uzhhorod', 'hub-kyiv',
            'hub-uman', 'hub-odesa', 'hub-mykolaiv', 'hub-kharkiv',
        ];
        $itOnly = [
            'hub-milan', 'hub-verona', 'hub-garda', 'hub-riva', 'hub-molveno',
            'hub-ortisei', 'hub-brunico', 'hub-braies', 'hub-misurina',
            'hub-cortina', 'hub-campiglio', 'hub-borghetto',
            'lake-garda', 'dolomites',
        ];

        if ($uaHubs !== []) {
            DB::table('golden_labels')->whereIn('slug', $uaHubs)->update(['country_code' => 'UA']);
        }
        if ($itOnly !== []) {
            DB::table('golden_labels')->whereIn('slug', $itOnly)->update(['country_code' => 'IT']);
        }
        DB::table('golden_labels')
            ->whereNotIn('slug', array_merge($uaHubs, $itOnly))
            ->update(['country_code' => 'BOTH']);
    }

    public function down(): void
    {
        Schema::table('golden_labels', function (Blueprint $table) {
            if (Schema::hasColumn('golden_labels', 'country_code')) {
                $table->dropColumn('country_code');
            }
        });
    }
};
