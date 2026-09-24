<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // alignement sur les statuts : EN_ATTENT, OUI, NON, BV, INJOINABLE
        DB::table('call_outcomes')
            ->where('outcome', 'BOITE_VOCALE')
            ->update(['outcome' => 'BV']);
    }

    public function down(): void
    {
        DB::table('call_outcomes')
            ->where('outcome', 'BV')
            ->update(['outcome' => 'BOITE_VOCALE']);
    }
};
