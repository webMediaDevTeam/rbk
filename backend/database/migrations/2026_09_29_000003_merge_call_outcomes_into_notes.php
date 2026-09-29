<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fusion de `call_outcomes` dans `notes` : une seule table d'historique.
 *
 * `notes` devient le journal d'événements du modèle :
 *
 *   id, client_id, sender_id (utilisateur ou 'SYSTEM'), type, description,
 *   created_at
 *
 * type réservé du modèle : RESERVED, YES, NO, BV, CALL_BACK, BLACKLISTED,
 * RETURNED_TO_AVAILABLE (+ NOTE pour un commentaire libre).
 *
 * Correspondances appliquées aux lignes existantes (MySQL de dev : 13 lignes) :
 *
 *   OUI         -> YES           BOITE_VOCALE -> BV     (legacy)
 *   NON         -> NO            BLACKLIST    -> BLACKLISTED
 *   BV          -> BV            UNBLACKLIST  -> RETURNED_TO_AVAILABLE
 *   INJOINABLE  -> CALL_BACK
 *
 * `sender_id` remplace la clé étrangère `comercial_id` : 'SYSTEM' (cron) n'a
 * pas d'utilisateur, et l'historique ne doit pas disparaître avec le compte.
 * `content` devient `description` (nullable : les événements n'ont pas de
 * texte). `due_date` / `call_duration_seconds` (note libre type TÂCHE, 0 ligne
 * en dev) sont retirés : aucune écriture possible depuis l'UI.
 *
 * ⚠️ Recréation complète de `notes` et non `dropColumn()` : une colonne
 * référencée par une clé étrangère ne peut être supprimée ni sur SQLite (les
 * tests) ni proprement sur MySQL.
 *
 * down() recrée `call_outcomes`, y recopie les événements, puis remet `notes`
 * au schéma de la note libre (meilleur effort pour RESERVED / NOTE, qui n'ont
 * pas d'équivalent).
 */
return new class extends Migration
{
    /** Ancien `call_outcomes.outcome` => nouveau `notes.type`. */
    private const OUTCOME_TO_TYPE = [
        'OUI' => 'YES',
        'NON' => 'NO',
        'BV' => 'BV',
        'BOITE_VOCALE' => 'BV',
        'INJOINABLE' => 'CALL_BACK',
        'BLACKLIST' => 'BLACKLISTED',
        'UNBLACKLIST' => 'RETURNED_TO_AVAILABLE',
    ];

    /** Nouveau `notes.type` => ancien `call_outcomes.outcome` (down). */
    private const TYPE_TO_OUTCOME = [
        'YES' => 'OUI',
        'NO' => 'NON',
        'BV' => 'BV',
        'CALL_BACK' => 'INJOINABLE',
        'BLACKLISTED' => 'BLACKLIST',
        'RETURNED_TO_AVAILABLE' => 'UNBLACKLIST',
    ];

    public function up(): void
    {
        // 1. Journal au schéma du modèle (table temporaire + copie + swap).
        Schema::create('notes_journal', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained()->cascadeOnDelete();
            $table->string('sender_id')->nullable();
            $table->string('type')->default('NOTE');
            $table->text('description')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('sender_id');
            $table->index(['client_id', 'created_at']);
        });

        foreach (DB::table('notes')->orderBy('created_at')->get() as $note) {
            DB::table('notes_journal')->insert([
                'id' => $note->id,
                'client_id' => $note->client_id,
                'sender_id' => $note->comercial_id,
                'type' => $note->type,
                'description' => $note->content,
                'created_at' => $note->created_at ?? now(),
            ]);
        }

        Schema::drop('notes');
        Schema::rename('notes_journal', 'notes');

        // 2. Les issues d'appel rejoignent le journal unique.
        foreach (DB::table('call_outcomes')->get() as $outcome) {
            DB::table('notes')->insert([
                'id' => $outcome->id,
                'client_id' => $outcome->client_id,
                'sender_id' => $outcome->comercial_id,
                'type' => self::OUTCOME_TO_TYPE[$outcome->outcome] ?? 'NOTE',
                'description' => $outcome->note,
                'created_at' => $outcome->created_at ?? now(),
            ]);
        }

        Schema::dropIfExists('call_outcomes');
    }

    public function down(): void
    {
        // 1. Recréation de la table d'origine (comercial_id nullable : le
        //    sender peut valoir 'SYSTEM' et il n'y a plus de garantie FK).
        Schema::create('call_outcomes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained()->cascadeOnDelete();
            $table->uuid('comercial_id')->nullable();
            $table->string('outcome');
            $table->text('note')->nullable();
            $table->integer('recall_amount')->nullable();
            $table->string('recall_unit')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('comercial_id');
        });

        $events = DB::table('notes')
            ->whereIn('type', array_keys(self::TYPE_TO_OUTCOME))
            ->get();

        foreach ($events as $event) {
            DB::table('call_outcomes')->insert([
                'id' => $event->id,
                'client_id' => $event->client_id,
                'comercial_id' => $this->senderAsUser($event->sender_id),
                'outcome' => self::TYPE_TO_OUTCOME[$event->type],
                'note' => $event->description,
                'created_at' => $event->created_at ?? now(),
            ]);
        }

        // Les événements ne vivent plus dans `notes` une fois recopiés.
        DB::table('notes')->whereIn('type', array_keys(self::TYPE_TO_OUTCOME))->delete();

        // 2. Retour au schéma de la note libre (table temporaire + swap).
        Schema::create('notes_legacy', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained()->cascadeOnDelete();
            $table->uuid('comercial_id')->nullable();
            $table->string('type')->default('GENERAL_NOTE');
            $table->text('content');
            $table->timestamp('due_date')->nullable();
            $table->integer('call_duration_seconds')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('comercial_id');
        });

        foreach (DB::table('notes')->orderBy('created_at')->get() as $note) {
            DB::table('notes_legacy')->insert([
                'id' => $note->id,
                'client_id' => $note->client_id,
                'comercial_id' => $this->senderAsUser($note->sender_id),
                'type' => $note->type,
                // `content` était NOT NULL : un événement sans texte devient ''.
                'content' => (string) $note->description,
                'created_at' => $note->created_at ?? now(),
            ]);
        }

        Schema::drop('notes');
        Schema::rename('notes_legacy', 'notes');
    }

    /**
     * sender_id -> clé étrangère utilisable au retour arrière.
     */
    private function senderAsUser(?string $senderId): ?string
    {
        if ($senderId === null || $senderId === 'SYSTEM') {
            return null;
        }

        $exists = DB::table('users')->where('id', $senderId)->exists();

        return $exists ? $senderId : null;
    }
};
