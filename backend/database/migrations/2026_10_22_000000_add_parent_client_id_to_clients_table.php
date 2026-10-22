<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rattachement des **doublons** de prospect :
 *
 *   clients.parent_client_id — pointeur vers la fiche d'origine (NULL = autonome)
 *
 * Une fiche reconnue comme répétée (même téléphone normalisé qu'une fiche déjà
 * présente) est marquée du statut `REPETE` (`Client::STATUS_REPETE`) et pointe
 * sa fiche d'origine via cette **clé étrangère auto-référente** (`clients.id`),
 * effacée en `NULL` quand l'origine disparaît (`nullOnDelete`).
 *
 * Index pour la performance :
 *  - `parent_client_id` seul — requêtes « les doublons de cette fiche » (et
 *    soutien de la contrainte FK côté MySQL) ;
 *  - (`phone_normalized`, `parent_client_id`) composite — détection de doublons
 *    : regrouper / joindre les fiches par téléphone normalisé tout en gardant
 *    le pointeur de parent à portée (index couvrant). `phone_normalized` porte
 *    déjà son index simple (migration 2026_10_07_000003), ici on ajoute la
 *    variante composée.
 *
 * La colonne `status` est un simple `string` (aucun `ENUM` en base) : le statut
 * `REPETE` est ajouté côté modèle, sans migration de type de colonne.
 *
 * Aucune donnée supprimée : `php artisan migrate` uniquement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Colonne posée sans `constrained()` : les index sont créés **avant**
            // la FK pour que MySQL les réutilise au lieu d'en générer un
            // redondant (cf. `add_current_reservation_to_clients_table`).
            $table->uuid('parent_client_id')->nullable()->after('status');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->index('parent_client_id');
            $table->index(['phone_normalized', 'parent_client_id'], 'clients_phone_parent_index');

            $table->foreign('parent_client_id')
                ->references('id')
                ->on('clients')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // SQLite (tests in-memory) ne sait pas supprimer une contrainte :
            // la colonne part seule, la FK disparaît avec la table.
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->dropForeign(['parent_client_id']);
            }

            $table->dropIndex('clients_phone_parent_index');
            $table->dropIndex(['parent_client_id']);
            $table->dropColumn('parent_client_id');
        });
    }
};
