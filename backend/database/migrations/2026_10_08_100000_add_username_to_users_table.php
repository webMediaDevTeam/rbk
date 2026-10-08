<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * « Nom d'utilisateur » — identifiant court saisi (ou déduit de la
     * partie avant « @ » de l'e-mail) dans les modales employé
     * « Créer / Modifier ».
     *
     * Colonne **unique** : la modale contrôle la disponibilité en temps
     * réel (`GET users/username-available`, debounce) avant l'envoi.
     * Aucune donnée n'est supprimée : `php artisan migrate` uniquement.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 100)->nullable()->unique()->after('email');
        });

        // Reprise des comptes existants : la partie avant « @ » devient le
        // nom d'utilisateur, sauf collision (colonne unique) ou caractères
        // non conformes à la règle de saisie — on laisse alors NULL.
        $taken = [];

        foreach (DB::table('users')->orderBy('created_at')->orderBy('id')->get() as $user) {
            $username = Str::lower(trim(Str::before((string) $user->email, '@')));

            if ($username === '' || isset($taken[$username]) || ! preg_match('/^[a-z0-9._-]{3,100}$/', $username)) {
                continue;
            }

            DB::table('users')->where('id', $user->id)->update(['username' => $username]);
            $taken[$username] = true;
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
