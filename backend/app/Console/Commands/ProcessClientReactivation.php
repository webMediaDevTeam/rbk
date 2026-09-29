<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Note;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ProcessClientReactivation extends Command
{
    protected $signature = 'clients:reactivate';

    protected $description = 'Reactivate UNAVAILABLE clients whose returned_at has passed (back to AVAILABLE for everyone)';

    public function handle(): int
    {
        $count = 0;

        Client::where('status', Client::STATUS_UNAVAILABLE)
            ->whereNotNull('returned_at')
            ->where('returned_at', '<=', now())
            ->chunkById(200, function ($clients) use (&$count) {
                foreach ($clients as $client) {
                    // Statut + note d'un seul bloc : jamais de client
                    // réactivé sans trace dans le journal.
                    DB::transaction(function () use ($client) {
                        $client->update([
                            'status' => Client::STATUS_AVAILABLE,
                            'returned_at' => null,
                        ]);

                        // Événement du journal : le retour est historisé
                        // (émetteur SYSTEM, aucun compte utilisateur).
                        // Description non limitée en longueur : elle est
                        // générée, pas saisie.
                        Note::create([
                            'client_id' => $client->id,
                            'sender_id' => Note::SENDER_SYSTEM,
                            'type' => Note::TYPE_RETURNED_TO_AVAILABLE,
                            'description' => sprintf(
                                'Client automatically returned to Available status on %s after temporary restriction period.',
                                now()->format('Y-m-d H:i:s')
                            ),
                        ]);
                    });

                    // Les réservations ne sont **pas** réécrites (aucun statut
                    // `REALIZED` dans le modèle) : un client `AVAILABLE` les
                    // rend automatiquement inactives, `Reservation::scopeActive()`
                    // exigeant un client `RESERVED` / `CONFIRMED`.
                    $count++;
                }
            });

        $this->info($count.' client(s) réactivé(s) -> AVAILABLE.');

        return self::SUCCESS;
    }
}
