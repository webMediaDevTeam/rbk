<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Découpe l'ancienne colonne JSON `rbq_data` en colonnes réelles :
     * `name` (rbq_data.name) et `enterprise_name` (rbq_data.entreprise_name).
     * Les métadonnées de seed (source, entreprise_index, client_index,
     * entreprise_id) ne sont pas conservées.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'name')) {
                $table->string('name')->nullable()->after('id');
            }

            if (! Schema::hasColumn('clients', 'enterprise_name')) {
                $table->string('enterprise_name')->nullable()->after('name');
            }
        });

        if (! Schema::hasColumn('clients', 'rbq_data')) {
            return;
        }

        DB::table('clients')
            ->whereNotNull('rbq_data')
            ->orderBy('id')
            ->chunk(100, function ($clients) {
                foreach ($clients as $client) {
                    $data = json_decode((string) $client->rbq_data, true);

                    if (! is_array($data)) {
                        continue;
                    }

                    DB::table('clients')->where('id', $client->id)->update([
                        'name' => $data['name'] ?? null,
                        'enterprise_name' => $data['entreprise_name'] ?? null,
                    ]);
                }
            });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('rbq_data');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'rbq_data')) {
                $table->jsonb('rbq_data')->nullable();
            }
        });

        DB::table('clients')
            ->where(function ($query) {
                $query->whereNotNull('name')->orWhereNotNull('enterprise_name');
            })
            ->orderBy('id')
            ->chunk(100, function ($clients) {
                foreach ($clients as $client) {
                    DB::table('clients')->where('id', $client->id)->update([
                        'rbq_data' => json_encode(array_filter([
                            'name' => $client->name,
                            'entreprise_name' => $client->enterprise_name,
                        ], fn ($value) => $value !== null)),
                    ]);
                }
            });

        Schema::table('clients', function (Blueprint $table) {
            if (Schema::hasColumn('clients', 'name')) {
                $table->dropColumn('name');
            }

            if (Schema::hasColumn('clients', 'enterprise_name')) {
                $table->dropColumn('enterprise_name');
            }
        });
    }
};
