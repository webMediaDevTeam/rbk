<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * État « terminé » d'un rappel (classe Rappel) :
 *
 *   rappels.done_at   — NULL = en attente, sinon date/heure de prise en compte
 *   rappels.done_note — note saisie par l'employé au moment du « Terminer »
 *
 * Les listes (« Rappels » / « Auto-rappels ») n'affichent que
 * `done_at IS NULL` ; la note est aussi écrite dans `notes` pour apparaître
 * dans l'historique du client. down() retire les deux colonnes, les rappels
 * en attente sont inchangés.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rappels', function (Blueprint $table) {
            $table->timestamp('done_at')->nullable()->after('reminder_date');
            $table->text('done_note')->nullable()->after('done_at');
            $table->index(['comercial_id', 'done_at']);
        });
    }

    public function down(): void
    {
        Schema::table('rappels', function (Blueprint $table) {
            $table->dropIndex(['comercial_id', 'done_at']);
            $table->dropColumn(['done_at', 'done_note']);
        });
    }
};
