<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stockage des appels + enregistrements RingCentral (docs/TODOS.md
 * « Sync Call Logs ») — l'onglet « Appels » d'une fiche employé se lit
 * alors **en local** (aucun appel API par ouverture, plus de `429 CMN-301`)
 * et l'historique survit aux coupures / quotas RingCentral.
 *
 *   call_logs        : un appel = un enregistrement du call log RingCentral
 *                      (`id` dé-doublonné) ou une session ouverte depuis
 *                      l'application (`ringcentral_session_id`).
 *   call_recordings  : les enregistrements audio d'un appel (métadonnées
 *                      seules — l'audio reste streamé par le proxy
 *                      `GET /call-logs/recordings/{id}/content`).
 *
 * Nullable partout côté RingCentral : une session ouverte « à chaud »
 * n'enrichit ses champs (durée, résultat, enregistrement) qu'au fil de la
 * synchro. `ringcentral_call_id` est unique mais **nullable** : les lignes
 * ouvertes avant la synchro n'ont pas encore l'id de call log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('employee_id')->nullable()->index();
            $table->string('ringcentral_call_id', 64)->nullable()->unique();
            $table->string('ringcentral_session_id', 64)->nullable()->index();
            $table->string('ringcentral_extension_id', 64)->nullable();
            $table->string('ringcentral_party_id', 64)->nullable();
            $table->string('direction', 16)->nullable();
            $table->string('type', 16)->nullable();
            $table->string('from_number', 32)->nullable();
            $table->string('from_name', 120)->nullable();
            $table->string('to_number', 32)->nullable();
            $table->string('to_name', 120)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration')->nullable();
            $table->string('result', 64)->nullable();
            $table->json('raw')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->foreign('employee_id')->references('id')->on('employees')->nullOnDelete();
        });

        Schema::create('call_recordings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('call_log_id')->index();
            $table->string('ringcentral_recording_id', 64)->unique();
            $table->string('type', 32)->nullable();
            $table->unsignedInteger('duration')->nullable();
            $table->string('file_name', 191)->nullable();
            $table->text('content_uri')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->foreign('call_log_id')->references('id')->on('call_logs')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_recordings');
        Schema::dropIfExists('call_logs');
    }
};
