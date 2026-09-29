<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Création de la table `rappels` (classe Rappel du modèle) :
 *
 *   rappels.id, client_id, comercial_id, reservation_id, reminder_date, created_at
 *
 * Les rappels vivaient jusqu'ici dans `reservations` (recall_at + rappel_after /
 * rappel_type). Les lignes existantes sont reportées dans `rappels`, puis les
 * trois colonnes sont retirées de `reservations` : `rappels` devient la source
 * de vérité unique. Le délai d'affichage (« Rappel dans X ») n'est plus stocké :
 * il est recalculé par Rappel::delay() à partir de reminder_date.
 *
 * Reprise de données (MySQL de dev : 6 rappels actifs) — down() restaure tout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rappels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('comercial_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('reservation_id')->constrained()->cascadeOnDelete();
            $table->timestamp('reminder_date');
            $table->timestamp('created_at')->useCurrent();

            $table->index('reminder_date');
            $table->index(['client_id', 'reminder_date']);
        });

        // Report des rappels existants (recall_at non nul).
        $recalls = DB::table('reservations')
            ->whereNotNull('recall_at')
            ->get(['id', 'client_id', 'comercial_id', 'recall_at', 'created_at']);

        foreach ($recalls as $recall) {
            DB::table('rappels')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'client_id' => $recall->client_id,
                'comercial_id' => $recall->comercial_id,
                'reservation_id' => $recall->id,
                'reminder_date' => $recall->recall_at,
                'created_at' => $recall->created_at ?? now(),
            ]);
        }

        // Fin de la double vérité : la réservation ne porte plus le rappel.
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn(['recall_at', 'rappel_after', 'rappel_type']);
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->timestamp('recall_at')->nullable();
            $table->integer('rappel_after')->nullable();
            $table->string('rappel_type')->nullable();
        });

        foreach (DB::table('rappels')->get() as $rappel) {
            $delay = $this->delay($rappel->reminder_date);

            DB::table('reservations')->where('id', $rappel->reservation_id)->update([
                'recall_at' => $rappel->reminder_date,
                'rappel_after' => $delay[0],
                'rappel_type' => $delay[1],
            ]);
        }

        Schema::dropIfExists('rappels');
    }

    /**
     * Même algorithme que l'ancien CallWorkflowService::recallDelay().
     *
     * @return array{0: int, 1: string} [montant, unité MINUTE|HEURE|JOUR]
     */
    private function delay(string $reminderDate): array
    {
        $minutes = (int) round(max(0, strtotime($reminderDate) - time()) / 60);

        if ($minutes < 60) {
            return [max(1, $minutes), 'MINUTE'];
        }

        if ($minutes < 1440) {
            return [(int) floor($minutes / 60), 'HEURE'];
        }

        return [(int) floor($minutes / 1440), 'JOUR'];
    }
};
