<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alignement du schéma `clients` sur le payload n8n.
 *
 * Tout ce qui existait déjà est conservé tel quel (noms et types) :
 *   licence_number, intervenant_name, licence_status, neq, full_address,
 *   municipality, administrative_region, phone, respondents,
 *   authorized_categories, surety_company, surety_amount,
 *   licence_start_date, licence_end_date.
 *
 * Seules les 2 attributs MANQUANTS sont ajoutés (décision projet) :
 *   - licence_propre_numero : « Licence (propre) » en ENTIER (clé métier n8n),
 *     le booléen `licence_propre` existant reste inchangé, la PK reste `id` UUID ;
 *   - cautionnement_compagnie : tableau JSON de noms, `surety_company` (string)
 *     reste inchangé.
 *
 * Les contraintes NOT NULL demandées sont appliquées au niveau du futur
 * webhook n8n (validation), pas en base, pour ne pas casser les lignes existantes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'licence_propre_numero')) {
                $table->unsignedInteger('licence_propre_numero')
                    ->nullable()
                    ->unique('clients_licence_propre_numero_unique')
                    ->after('licence_propre');
            }

            if (! Schema::hasColumn('clients', 'cautionnement_compagnie')) {
                $table->json('cautionnement_compagnie')->nullable()->after('surety_company');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (Schema::hasColumn('clients', 'cautionnement_compagnie')) {
                $table->dropColumn('cautionnement_compagnie');
            }
        });

        Schema::table('clients', function (Blueprint $table) {
            if (Schema::hasColumn('clients', 'licence_propre_numero')) {
                $table->dropUnique('clients_licence_propre_numero_unique');
                $table->dropColumn('licence_propre_numero');
            }
        });
    }
};
